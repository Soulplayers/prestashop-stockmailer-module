<?php
/**
 * 2007-2015 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2015 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_CAN_LOAD_FILES_')) {
    exit;
}


class StockMailer extends Module
{
    protected $html = '';

    protected $merchant_mails;
    protected $merchant_order;
    protected $merchant_oos;

    const __MA_MAIL_DELIMITOR__ = "\n";

    public function __construct()
    {
        $this->name = 'stockmailer';
        $this->tab = 'administration';
        $this->version = '0.0.1';
        $this->author = 'SoulPlayer';
        $this->need_instance = 0;

        $this->controllers = array('account');

        $this->bootstrap = true;
        parent::__construct();

        if ($this->id) {
            $this->init();
        }

        $this->displayName = $this->getTranslator()->trans('Stock Mailer', array());
        $this->description = $this->getTranslator()->trans('This module will automatically send the quantity of products remaining in stock by e-mail whenever the stock of a product is changed.', array());
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];
    }

    protected function init()
    {
        $this->merchant_mails = str_replace(',', self::__MA_MAIL_DELIMITOR__, (string) Configuration::get('MA_MERCHANT_MAILS'));
        $this->merchant_order = (int) Configuration::get('MA_MERCHANT_ORDER');
        $this->merchant_oos = (int) Configuration::get('MA_MERCHANT_OOS');
    }

    public function install($delete_params = true)
    {
        if (!parent::install() ||
            !$this->registerHook('actionUpdateQuantity') ||
            !$this->registerHook('displayProductButtons') ||
            !$this->registerHook('actionProductDelete') ||
            !$this->registerHook('actionProductAttributeDelete') ||
            !$this->registerHook('actionProductAttributeUpdate') ||
            !$this->registerHook('actionProductOutOfStock') ||
            !$this->registerHook('actionOrderReturn') ||
            !$this->registerHook('actionOrderEdited')) {
            return false;
        }

        if ($delete_params) {
            Configuration::updateValue('MA_MERCHANT_ORDER', 1);
            Configuration::updateValue('MA_MERCHANT_OOS', 1);
            Configuration::updateValue('MA_MERCHANT_MAILS', Configuration::get('PS_SHOP_EMAIL'));

            $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.MailAlert::$definition['table'].'`
				(
					`id_customer` int(10) unsigned NOT NULL,
					`customer_email` varchar(128) NOT NULL,
					`id_product` int(10) unsigned NOT NULL,
					`id_product_attribute` int(10) unsigned NOT NULL,
					`id_shop` int(10) unsigned NOT NULL,
					`id_lang` int(10) unsigned NOT NULL,
					PRIMARY KEY  (`id_customer`,`customer_email`,`id_product`,`id_product_attribute`,`id_shop`)
				) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci';

            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }

        return true;
    }

    public function uninstall($delete_params = true)
    {
        return parent::uninstall();
    }

    public function reset()
    {
        if (!$this->uninstall(false)) {
            return false;
        }
        if (!$this->install(false)) {
            return false;
        }

        return true;
    }

    public function getContent()
    {
        $this->html = '';

        $this->postProcess();

        $this->html .= $this->renderForm();

        return $this->html;
    }

    protected function postProcess()
    {
        $errors = array();

        if (Tools::isSubmit('submitMAMerchant')) {
            $emails = (string) Tools::getValue('MA_MERCHANT_MAILS');

            if (!$emails || empty($emails)) {
                $errors[] = $this->getTranslator()->trans('Please type one (or more) e-mail address', array());
            } else {
                $emails = str_replace(',', self::__MA_MAIL_DELIMITOR__, $emails);
                $emails = explode(self::__MA_MAIL_DELIMITOR__, $emails);
                foreach ($emails as $k => $email) {
                    $email = trim($email);
                    if (!empty($email) && !Validate::isEmail($email)) {
                        $errors[] = $this->getTranslator()->trans('Invalid e-mail:', array()).' '.Tools::safeOutput($email);
                        break;
                    } elseif (!empty($email) && count($email) > 0) {
                        $emails[$k] = $email;
                    } else {
                        unset($emails[$k]);
                    }
                }

                $emails = implode(self::__MA_MAIL_DELIMITOR__, $emails);

                if (!Configuration::updateValue('MA_MERCHANT_MAILS', (string) $emails)) {
                    $errors[] = $this->getTranslator()->trans('Cannot update settings', array());
                } elseif (!Configuration::updateValue('MA_MERCHANT_ORDER', (int) Tools::getValue('MA_MERCHANT_ORDER'))) {
                    $errors[] = $this->getTranslator()->trans('Cannot update settings', array());
                } elseif (!Configuration::updateValue('MA_MERCHANT_OOS', (int) Tools::getValue('MA_MERCHANT_OOS'))) {
                    $errors[] = $this->getTranslator()->trans('Cannot update settings', array());
                }
            }
        }

        if (count($errors) > 0) {
            $this->html .= $this->displayError(implode('<br />', $errors));
        } else if (Tools::isSubmit('submitMailAlert') || Tools::isSubmit('submitMAMerchant')) {
            $this->html .= $this->displayConfirmation($this->getTranslator()->trans('Settings updated successfully', array()));
        }

        $this->init();
    }

    public function hookDisplayProductButtons($params)
    {
        if (0 < $params['product']['quantity'] ||
            !Configuration::get('PS_STOCK_MANAGEMENT') ||
            Product::isAvailableWhenOutOfStock($params['product']['out_of_stock']))
            return;
        $context = Context::getContext();
        $id_product = (int)$params['product']['id'];
        $id_product_attribute = $params['product']['id_product_attribute'];
        $id_customer = (int)$context->customer->id;
        if ((int)$context->customer->id <= 0)
            $this->context->smarty->assign('email', 1);
        elseif (MailAlert::customerHasNotification($id_customer, $id_product, $id_product_attribute, (int)$context->shop->id))
            return;
        $this->context->smarty->assign(
            array(
                'id_product' => $id_product,
                'id_product_attribute' => $id_product_attribute
            )
        );
        return $this->display(__FILE__, 'product.tpl');
    }

    public function hookActionUpdateQuantity($params)
    {
        $id_product = (int) $params['id_product'];
        $id_product_attribute = (int) $params['id_product_attribute'];

        $quantity = (int) $params['quantity'];
        $context = Context::getContext();
        $id_shop = (int) $context->shop->id;
        $id_lang = (int) $context->language->id;
        $product = new Product($id_product, false, $id_lang, $id_shop, $context);
        $product_has_attributes = $product->hasAttributes();
        $configuration = Configuration::getMultiple(
            array(
                'PS_STOCK_MANAGEMENT',
                'PS_SHOP_EMAIL',
                'PS_SHOP_NAME',
            ), null, null, $id_shop
        );
        $check_oos = ($product_has_attributes && $id_product_attribute) || (!$product_has_attributes && !$id_product_attribute);

        if ($check_oos &&
            $product->active == 1 &&
            (int) $quantity &&
            !(!$this->merchant_oos || empty($this->merchant_mails)) &&
            $configuration['PS_STOCK_MANAGEMENT']) {
            $iso = Language::getIsoById($id_lang);
            $product_name = Product::getProductName($id_product, $id_product_attribute, $id_lang);
            $template_vars = array(
                '{qty}' => $quantity,
                '{product}' => $product_name,
            );

            // Do not send mail if multiples product are created / imported.
            if (!defined('PS_MASS_PRODUCT_CREATION') &&
                file_exists(dirname(__FILE__).'/mails/'.$iso.'/productoutofstock.txt') &&
                file_exists(dirname(__FILE__).'/mails/'.$iso.'/productoutofstock.html')) {
                // Send 1 email by merchant mail, because Mail::Send doesn't work with an array of recipients
                $merchant_mails = explode(self::__MA_MAIL_DELIMITOR__, $this->merchant_mails);
                foreach ($merchant_mails as $merchant_mail) {
                    Mail::Send(
                        $id_lang,
                        'productoutofstock',
                        Mail::l('Modification du stock produit', $id_lang),
                        $template_vars,
                        $merchant_mail,
                        null,
                        (string) $configuration['PS_SHOP_EMAIL'],
                        (string) $configuration['PS_SHOP_NAME'],
                        null,
                        null,
                        dirname(__FILE__).'/mails/',
                        false,
                        $id_shop
                    );
                }
            }
        }

    }

    public function hookActionProductAttributeUpdate($params)
    {
        $sql = '
			SELECT `id_product`, `quantity`
			FROM `'._DB_PREFIX_.'stock_available`
			WHERE `id_product_attribute` = '.(int) $params['id_product_attribute'];

        $result = Db::getInstance()->getRow($sql);

    }

    public function hookActionProductDelete($params)
    {
        $sql = '
			DELETE FROM `'._DB_PREFIX_.MailAlert::$definition['table'].'`
			WHERE `id_product` = '.(int) $params['product']->id;

        Db::getInstance()->execute($sql);
    }

    public function hookActionAttributeDelete($params)
    {
        if ($params['deleteAllAttributes']) {
            $sql = '
				DELETE FROM `'._DB_PREFIX_.MailAlert::$definition['table'].'`
				WHERE `id_product` = '.(int) $params['id_product'];
        } else {
            $sql = '
				DELETE FROM `'._DB_PREFIX_.MailAlert::$definition['table'].'`
				WHERE `id_product_attribute` = '.(int) $params['id_product_attribute'].'
				AND `id_product` = '.(int) $params['id_product'];
        }

        Db::getInstance()->execute($sql);
    }

    public function renderForm()
    {
        $inputs = array(
            array(
                'type' => 'switch',
                'is_bool' => true, //retro compat 1.5
                'label' => $this->getTranslator()->trans('Stock change', array()),
                'name' => 'MA_MERCHANT_OOS',
                'desc' => $this->getTranslator()->trans('Receive a notification if the available quantity of a product is changing', array()),
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->getTranslator()->trans('Enabled', array()),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->getTranslator()->trans('Disabled', array()),
                    ),
                ),
            ),
        );

        $inputs[] = array(
                'type' => 'textarea',
                'cols' => 36,
                'rows' => 4,
                'label' => $this->getTranslator()->trans('E-mail addresses', array()),
                'name' => 'MA_MERCHANT_MAILS',
                'desc' => $this->getTranslator()->trans('One e-mail address per line (e.g. bob@example.com).', array()),
        );

        $fields_form_2 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->getTranslator()->trans('Stock notifications', array()),
                    'icon' => 'icon-cogs',
                ),
                'input' => $inputs,
                'submit' => array(
                    'title' => $this->getTranslator()->trans('Save', array()),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitMAMerchant',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMailAlertConfiguration';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name
            .'&tab_module='.$this->tab
            .'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields_form_2));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'MA_MERCHANT_ORDER' => Tools::getValue('MA_MERCHANT_ORDER', Configuration::get('MA_MERCHANT_ORDER')),
            'MA_MERCHANT_OOS' => Tools::getValue('MA_MERCHANT_OOS', Configuration::get('MA_MERCHANT_OOS')),
            'MA_MERCHANT_MAILS' => Tools::getValue('MA_MERCHANT_MAILS', Configuration::get('MA_MERCHANT_MAILS')),
        );
    }
}
