<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShop\PrestaShop\Core\Shop;

use Configuration;
use Context;
use ImageManager;
use PrestaShop\PrestaShop\Core\Domain\Shop\DTO\ShopLogoSettings;
use PrestaShopException;
use Shop;
use Tools;

/**
 * Class LogoUploader used to manage upload of Shop logos and favicon.
 */
class LogoUploader
{
    /**
     * @var Shop
     */
    private $shop;

    /**
     * @var array
     */
    private $errors = [];

    /**
     * @var array - a list of svg mime types
     */
    protected const SVG_MIMETYPES = ['image/svg+xml', 'image/svg'];

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    public function updateHeader()
    {
        if ($this->update('PS_LOGO', 'logo')) {
            list($width, $height) = getimagesize(_PS_IMG_DIR_ . Configuration::get('PS_LOGO'));
            Configuration::updateValue('SHOP_LOGO_HEIGHT', (int) round($height));
            Configuration::updateValue('SHOP_LOGO_WIDTH', (int) round($width));
        }
    }

    public function updateMail()
    {
        $this->update('PS_LOGO_MAIL', 'logo_mail');
    }

    public function updateInvoice()
    {
        $this->update('PS_LOGO_INVOICE', 'logo_invoice');
    }

    public function updateFavicon()
    {
        $shopId = (int) $this->shop->id;
        if ($shopId == Configuration::get('PS_SHOP_DEFAULT')) {
            $this->uploadIco('PS_FAVICON', _PS_IMG_DIR_ . 'favicon.ico');
        }
        if ($this->uploadIco('PS_FAVICON', _PS_IMG_DIR_ . 'favicon-' . $shopId . '.ico')) {
            Configuration::updateValue('PS_FAVICON', 'favicon-' . $shopId . '.ico');
        }

        Configuration::updateGlobalValue('PS_FAVICON', 'favicon.ico');
    }

    /**
     * Generic function which allows logo upload.
     *
     * @param string $fieldName
     * @param string $logoPrefix
     * @param array<string,array<string,string>> $files[] the array of files to avoid use $_POST
     *
     * @return bool
     *
     * @throws PrestaShopException in case of upload failure
     */
    public function update($fieldName, $logoPrefix, array $files = [])
    {
        $files = empty($files) ? $_FILES : $files;

        if (isset($files[$fieldName]['tmp_name'], $files[$fieldName]['tmp_name'], $files[$fieldName]['size'])) {
            $availableExtensions = (in_array($fieldName, ['PS_LOGO_MAIL', 'PS_LOGO_INVOICE'])) ? ShopLogoSettings::AVAILABLE_MAIL_AND_INVOICE_LOGO_IMAGE_EXTENSIONS : ShopLogoSettings::AVAILABLE_LOGO_IMAGE_EXTENSIONS;
            if ($error = ImageManager::validateUpload($files[$fieldName], Tools::getMaxUploadSize(), $availableExtensions)) {
                throw new PrestaShopException($error);
            }
            $tmpName = tempnam(_PS_TMP_IMG_DIR_, 'PS');

            if (!$tmpName || !move_uploaded_file($files[$fieldName]['tmp_name'], $tmpName)) {
                throw new PrestaShopException(sprintf('%Upload of temporary file to %s has failed.', $tmpName));
            }

            if ($this->isSvgMimeType($files[$fieldName]['type'])) {
                $fileExtension = '.svg';
            } else {
                $fileExtension = ($fieldName == 'PS_STORES_ICON') ? '.gif' : '.jpg';
            }
            $logoName = $this->getLogoName($logoPrefix, $fileExtension);

            if ($fieldName == 'PS_STORES_ICON') {
                if (!@ImageManager::resize($tmpName, _PS_IMG_DIR_ . $logoName, null, null, 'gif', true)) {
                    throw new PrestaShopException(sprintf('An error occurred while attempting to copy shop icon %s.', $logoName));
                }
            } else {
                if ($this->isSvgMimeType($files[$fieldName]['type'])) {
                    if (!copy($tmpName, _PS_IMG_DIR_ . $logoName)) {
                        throw new PrestaShopException(sprintf('An error occurred while attempting to copy shop logo %s.', $logoName));
                    }
                } elseif (!@ImageManager::resize($tmpName, _PS_IMG_DIR_ . $logoName)) {
                    throw new PrestaShopException(sprintf('An error occurred while attempting to copy shop logo %s.', $logoName));
                }
            }

            $idShop = $this->shop->id;
            $idShopGroup = null;

            // on updating PS_LOGO if the new file is an svg, keep old logo for mail and invoice
            $deleteOldLogo = true;
            if ($fieldName == 'PS_LOGO' && $this->isSvgMimeType($files[$fieldName]['type'])) {
                if (empty(Configuration::get('PS_LOGO_MAIL'))) {
                    Configuration::updateValue('PS_LOGO_MAIL', Configuration::get('PS_LOGO'));
                    $deleteOldLogo = false;
                }
                if (empty(Configuration::get('PS_LOGO_INVOICE'))) {
                    Configuration::updateValue('PS_LOGO_INVOICE', Configuration::get('PS_LOGO'));
                    $deleteOldLogo = false;
                }
            }

            // manage deleting old logo
            if (!count($this->errors) && @filemtime(_PS_IMG_DIR_ . Configuration::get($fieldName)) && $deleteOldLogo) {
                if (Shop::isFeatureActive()) {
                    $this->updateInMultiShopContext($idShop, $idShopGroup, $fieldName);
                } else {
                    @unlink(_PS_IMG_DIR_ . Configuration::get($fieldName));
                }
            }

            Configuration::updateValue($fieldName, $logoName, false, $idShopGroup, $idShop);
            unlink($tmpName);

            return true;
        }

        return false;
    }

    public function isSvgMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SVG_MIMETYPES);
    }

    private function updateInMultiShopContext(&$idShop, &$idShopGroup, $fieldName)
    {
        if (Shop::getContext() == Shop::CONTEXT_SHOP) {
            $idShop = Shop::getContextShopID();
            $idShopGroup = Shop::getContextShopGroupID();
            Shop::setContext(Shop::CONTEXT_ALL);
            $logoAll = Configuration::get($fieldName);
            Shop::setContext(Shop::CONTEXT_GROUP);
            $logoGroup = Configuration::get($fieldName);
            Shop::setContext(Shop::CONTEXT_SHOP);
            $logoShop = Configuration::get($fieldName);
            if ($logoAll != $logoShop && $logoGroup != $logoShop && $logoShop != false) {
                @unlink(_PS_IMG_DIR_ . Configuration::get($fieldName));
            }
        } elseif (Shop::getContext() == Shop::CONTEXT_GROUP) {
            $idShopGroup = Shop::getContextShopGroupID();
            Shop::setContext(Shop::CONTEXT_ALL);
            $logoAll = Configuration::get($fieldName);
            Shop::setContext(Shop::CONTEXT_GROUP);
            if ($logoAll != Configuration::get($fieldName)) {
                @unlink(_PS_IMG_DIR_ . Configuration::get($fieldName));
            }
        }
    }

    public function uploadIco($name, $destination, $files = [])
    {
        $files = empty($files) ? $_FILES : $files;

        if (isset($files[$name]['tmp_name']) && !empty($files[$name]['tmp_name'])) {
            if ($error = ImageManager::validateIconUpload($files[$name])) {
                throw new PrestaShopException($error);
            } elseif (!copy($_FILES[$name]['tmp_name'], $destination)) {
                throw new PrestaShopException(Context::getContext()->getTranslator()->trans('An error occurred while uploading the favicon: cannot copy file "%s" to folder "%s".', [$files[$name]['tmp_name'], $destination], 'Admin.Design.Notification'));
            }
        }

        return !count($this->errors);
    }

    /**
     * @param string $logoPrefix
     * @param string $fileExtension
     *
     * @return string
     */
    private function getLogoName($logoPrefix, $fileExtension)
    {
        $shopId = $this->shop->id;

        $logoName = $logoPrefix
            . '-'
            . (int) Configuration::get('PS_IMG_UPDATE_TIME')
            . (int) $shopId . $fileExtension;

        if ($this->shop->getContext() == Shop::CONTEXT_ALL
            || $shopId == 0
            || Shop::isFeatureActive() == false
        ) {
            $logoName = $logoPrefix . '-' . (int) Configuration::get('PS_IMG_UPDATE_TIME') . $fileExtension;
        }

        return $logoName;
    }
}
