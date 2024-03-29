<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Controller
 */

namespace jtl\Connector\Shopware\Mapper;

use jtl\Connector\Core\Utilities\Money;
use jtl\Connector\Shopware\Model\ProductVariation;
use \jtl\Connector\Shopware\Utilities\Mmc;
use \jtl\Connector\Model\Product as ProductModel;
use \jtl\Connector\Model\ProductChecksum;
use jtl\Connector\Shopware\Utilities\VariationType;
use \Shopware\Components\Api\Exception as ApiException;
use \jtl\Connector\Core\Exception\DatabaseException;
use \jtl\Connector\Shopware\Utilities\Translation as TranslationUtil;
use \jtl\Connector\Core\Logger\Logger;
use \jtl\Connector\Model\Identity;
use \jtl\Connector\Shopware\Utilities\CustomerGroup as CustomerGroupUtil;
use \Doctrine\Common\Collections\ArrayCollection;
use \jtl\Connector\Shopware\Utilities\Locale as LocaleUtil;
use \Shopware\Models\Article\Detail as DetailSW;
use \Shopware\Models\Article\Article as ArticleSW;
use \Shopware\Models\Article\Download as DownloadSW;
use \Shopware\Models\Article\Link as LinkSW;
use \jtl\Connector\Core\Utilities\Language as LanguageUtil;
use \jtl\Connector\Shopware\Utilities\IdConcatenator;
use \jtl\Connector\Shopware\Model\Helper\ProductNameHelper;
use \jtl\Connector\Formatter\ExceptionFormatter;
use \jtl\Connector\Linker\ChecksumLinker;
use \jtl\Connector\Shopware\Mapper\ProductPrice as ProductPriceMapper;
use \jtl\Connector\Shopware\Model\ProductAttr;

class Product extends DataMapper
{
    protected static $masterProductIds = array();

    public function getRepository()
    {
        return Shopware()->Models()->getRepository('Shopware\Models\Article\Article');
    }

    public function find($id)
    {
        return (intval($id) == 0) ? null : $this->Manager()->find('Shopware\Models\Article\Article', $id);
    }

    public function findDetail($id)
    {
        return (intval($id) == 0) ? null : $this->Manager()->find('Shopware\Models\Article\Detail', $id);
    }

    public function findDetailBy(array $kv)
    {
        return $this->Manager()->getRepository('Shopware\Models\Article\Detail')->findOneBy($kv);
    }

    public function findAll($limit = 100, $count = false)
    {
        if ($count) {
            $query = $this->Manager()->createQueryBuilder()->select('detail')
                ->from('jtl\Connector\Shopware\Model\Linker\Detail', 'detail')
                ->leftJoin('detail.linker', 'linker')
                ->where('linker.hostId IS NULL')
                ->getQuery();

            $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($query, $fetchJoinCollection = true);

            return $paginator->count();
        }

        $query = $this->Manager()->createQueryBuilder()->select(
                'detail',
                'article',
                'unit',
                'tax',
                'categories',
                'maindetail',
                'detailprices',
                'prices',
                'links',
                'attribute',
                'downloads',
                'supplier',
                'pricegroup',
                'discounts',
                'customergroups',
                'configuratorOptions',
                'propertyvalues'
            )
            ->from('jtl\Connector\Shopware\Model\Linker\Detail', 'detail')
            ->leftJoin('detail.linker', 'linker')
            ->leftJoin('detail.article', 'article')
            ->leftJoin('detail.prices', 'detailprices')
            ->leftJoin('detail.unit', 'unit')
            ->leftJoin('article.tax', 'tax')
            ->leftJoin('article.categories', 'categories')
            ->leftJoin('article.mainDetail', 'maindetail')
            ->leftJoin('maindetail.prices', 'prices')            
            ->leftJoin('article.links', 'links')
            ->leftJoin('article.attribute', 'attribute', \Doctrine\ORM\Query\Expr\Join::WITH, 'attribute.articleDetailId = detail.id')
            ->leftJoin('article.downloads', 'downloads')
            ->leftJoin('article.supplier', 'supplier')
            ->leftJoin('article.priceGroup', 'pricegroup')
            ->leftJoin('pricegroup.discounts', 'discounts')
            ->leftJoin('article.customerGroups', 'customergroups')
            ->leftJoin('detail.configuratorOptions', 'configuratorOptions')
            ->leftJoin('article.propertyValues', 'propertyvalues')
            ->where('linker.hostId IS NULL')
            ->orderBy('detail.kind', 'ASC')
            ->setFirstResult(0)
            ->setMaxResults($limit)
            ->getQuery()->setHydrationMode(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($query, $fetchJoinCollection = true);

        $products = iterator_to_array($paginator);

        $shopMapper = Mmc::getMapper('Shop');
        $shops = $shopMapper->findAll(null, null);

        $translationUtil = new TranslationUtil();
        for ($i = 0; $i < count($products); $i++) {
            foreach ($shops as $shop) {
                $translation = $translationUtil->read($shop['id'], 'article', $products[$i]['articleId']);
                if (!empty($translation)) {
                    $translation['shopId'] = $shop['id'];
                    $products[$i]['translations'][$shop['locale']['locale']] = $translation;
                }
            }
        }

        return $products;
    }

    public function fetchCount()
    {
        return (int) Shopware()->Db()->fetchOne(
            'SELECT count(*)
                FROM s_articles_details d
                LEFT JOIN jtl_connector_link_detail l ON l.product_id = d.articleID
                    AND l.detail_id = d.id
                WHERE l.host_id IS NULL'
        );
    }

    public function fetchDetailCount($productId)
    {
        return Shopware()->Db()->fetchOne(
            'SELECT count(*) FROM s_articles_details WHERE articleID = ?',
            array($productId)
        );
    }

    public function deleteDetail($detailId)
    {
        return Shopware()->Db()->delete('s_articles_details', array('id = ?' => $detailId));
    }

    public function getParentDetailId($productId)
    {
        return (int) Shopware()->Db()->fetchOne(
            'SELECT id FROM s_articles_details WHERE articleID = ? AND kind = 0',
            array($productId)
        );
    }

    public function delete(ProductModel $product)
    {
        $result = new ProductModel();

        $this->deleteProductData($product);

        // Result
        $result->setId(new Identity('', $product->getId()->getHost()));

        return $result;
    }

    public function save(ProductModel $product)
    {
        $productSW = null;
        $detailSW = null;
        //$result = new ProductModel();
        $result = $product;
        $attrMappings = [];

        /*
        Logger::write(sprintf('>>> Product with id (%s, %s), masterProductId (%s, %s), manufacturerId (%s, %s)',
            $product->getId()->getEndpoint(),
            $product->getId()->getHost(),
            $product->getMasterProductId()->getEndpoint(),
            $product->getMasterProductId()->getHost(),
            $product->getManufacturerId()->getEndpoint(),
            $product->getManufacturerId()->getHost()
        ), Logger::DEBUG, 'database');
        */

        try {
            if ($this->isChild($product)) {
                if (isset(self::$masterProductIds[$product->getMasterProductId()->getHost()])) {
                    $product->getMasterProductId()->setEndpoint(self::$masterProductIds[$product->getMasterProductId()->getHost()]);
                }

                $this->prepareChildAssociatedData($product, $productSW, $detailSW);
                $this->prepareDetailAssociatedData($product, $productSW, $detailSW, true);
                $this->prepareAttributeAssociatedData($product, $productSW, $detailSW, $attrMappings, true);
                $this->preparePriceAssociatedData($product, $productSW, $detailSW);
                $this->prepareUnitAssociatedData($product, $detailSW);
                $this->prepareMeasurementUnitAssociatedData($product, $detailSW);

                // First Child
                if ($this->fetchDetailCount($productSW->getId()) == 1) {
                    // Set new main detail
                    $productSW->setMainDetail($detailSW);
                }

                $this->Manager()->persist($detailSW);
                $this->Manager()->persist($productSW);
                $this->Manager()->flush();
                
                $this->prepareDetailVariationAssociatedData($product, $detailSW);
                
            } else {
                $this->prepareProductAssociatedData($product, $productSW, $detailSW);
                $this->prepareCategoryAssociatedData($product, $productSW);
                $this->prepareInvisibilityAssociatedData($product, $productSW);
                $this->prepareTaxAssociatedData($product, $productSW);
                $this->prepareManufacturerAssociatedData($product, $productSW);
                // $this->prepareSpecialPriceAssociatedData($product, $productSW); Can not be fully supported

                $this->prepareDetailAssociatedData($product, $productSW, $detailSW);
                $this->prepareVariationAssociatedData($product, $productSW);
                $this->prepareSpecificAssociatedData($product, $productSW, $detailSW);
                $this->prepareAttributeAssociatedData($product, $productSW, $detailSW, $attrMappings);
                $this->preparePriceAssociatedData($product, $productSW, $detailSW);
                $this->prepareUnitAssociatedData($product, $detailSW);
                $this->prepareMeasurementUnitAssociatedData($product, $detailSW);
                $this->prepareMediaFileAssociatedData($product, $productSW);

                if (!($detailSW->getId() > 0)) {
                    $kind = $detailSW->getKind();
                    $productSW->setMainDetail($detailSW);
                    $detailSW->setKind($kind);
                    $productSW->setDetails(array($detailSW));
                }

                // Save Product
                $this->Manager()->persist($detailSW);
                $this->Manager()->persist($productSW);
                $this->Manager()->flush();

                if ($this->isParent($product) && $productSW !== null) {
                    self::$masterProductIds[$product->getId()->getHost()] = IdConcatenator::link(array($productSW->getMainDetail()->getId(), $productSW->getId()));
                }

                $this->prepareSetVariationRelations($product, $productSW);
                $this->saveVariationTranslationData($product, $productSW);
                $this->deleteTranslationData($productSW);
                $this->saveTranslationData($product, $productSW, $attrMappings);
            }
        } catch (\Exception $e) {
            Logger::write(sprintf('Exception from Product (%s, %s)', $product->getId()->getEndpoint(), $product->getId()->getHost()), Logger::ERROR, 'database');
            Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
        }

        // Result
        $result->setId(new Identity('', $product->getId()->getHost()))
            ->setChecksums($product->getChecksums());
        if ($detailSW !== null && $productSW !== null && (int) $detailSW->getId() > 0 && (int) $productSW->getId() > 0) {
            $result->setId(new Identity(IdConcatenator::link(array($detailSW->getId(), $productSW->getId())), $product->getId()->getHost()))
                ->setChecksums($product->getChecksums());
        }

        return $result;
    }

    protected function prepareChildAssociatedData(ProductModel &$product, ArticleSW &$productSW = null, DetailSW &$detailSW = null)
    {
        $productId = (strlen($product->getId()->getEndpoint()) > 0) ? $product->getId()->getEndpoint() : null;
        $masterProductId = (strlen($product->getMasterProductId()->getEndpoint()) > 0) ? $product->getMasterProductId()->getEndpoint() : null;

        if ($masterProductId === null) {
            throw new \Exception('Master product id is empty');
        }

        list($detailId, $id) = IdConcatenator::unlink($masterProductId);
        $productSW = $this->find($id);
        if ($productSW === null) {
            throw new \Exception(sprintf('Cannot find parent product with id (%s)', $masterProductId));
        }

        if ($productId !== null) {
            list($detailId, $id) = IdConcatenator::unlink($productId);
            $detailSW = $this->findDetail((int) $detailId);

            if ($detailSW === null && strlen($product->getSku()) > 0) {
                $detailSW = Shopware()->Models()->getRepository('Shopware\Models\Article\Detail')->findOneBy(array('number' => $product->getSku()));
            }
        }
    }

    protected function prepareProductAssociatedData(ProductModel $product, ArticleSW &$productSW = null, DetailSW &$detailSW = null)
    {
        $productId = (strlen($product->getId()->getEndpoint()) > 0) ? $product->getId()->getEndpoint() : null;

        if ($productId !== null) {
            list($detailId, $id) = IdConcatenator::unlink($productId);
            $detailSW = $this->findDetail((int) $detailId);

            if ($detailSW === null) {
                throw new \Exception(sprintf('Child product with id (%s) not found', $productId));
            }

            $productSW = $this->find((int) $id);
            if ($productSW && $detailSW === null) {
                $detailSW = $productSW->getMainDetail();
            }
        } elseif (strlen($product->getSku()) > 0) {
            $detailSW = Shopware()->Models()->getRepository('Shopware\Models\Article\Detail')->findOneBy(array('number' => $product->getSku()));
            if ($detailSW) {
                $productSW = $detailSW->getArticle();
            }
        }

        $isNew = false;
        if ($productSW === null) {
            $productSW = new ArticleSW();
            $isNew = true;
        }

        $productSW->setAdded($product->getCreationDate())
            ->setAvailableFrom($product->getAvailableFrom())
            ->setHighlight(intval($product->getIsTopProduct()))
            ->setActive($product->getIsActive());

        $inStock = 0;
        if ($product->getConsiderStock()) {
            $inStock = $product->getPermitNegativeStock() ? 0 : 1;
        }

        $productSW->setLastStock($inStock);

        // I18n
        foreach ($product->getI18ns() as $i18n) {
            if ($i18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                $productSW->setDescription($i18n->getMetaDescription())
                    ->setDescriptionLong($i18n->getDescription())
                    ->setKeywords($i18n->getMetaKeywords())
                    ->setMetaTitle($i18n->getTitleTag());
            }
        }

        $helper = ProductNameHelper::build($product);
        $productSW->setName($helper->getProductName());

        if ($isNew) {
            $this->Manager()->persist($productSW);
            $this->Manager()->flush();
        }
    }

    protected function prepareCategoryAssociatedData(ProductModel $product, ArticleSW &$productSW)
    {
        $collection = new ArrayCollection();
        $categoryMapper = Mmc::getMapper('Category');
        $useMapping = Application()->getConfig()->read('category_mapping');
        foreach ($product->getCategories() as $category) {
            if (strlen($category->getCategoryId()->getEndpoint()) > 0) {
                $categorySW = $categoryMapper->find(intval($category->getCategoryId()->getEndpoint()));
                if ($categorySW) {
                    $collection->add($categorySW);

                    // Category Mapping
                    if ($useMapping) {
                        foreach ($product->getI18ns() as $i18n) {
                            if ($i18n->getLanguageISO() !== LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale()) && strlen($i18n->getName()) > 0) {
                                $categoryMapping = $categoryMapper->findCategoryMappingByParent($categorySW->getId(), $i18n->getLanguageISO());
                                if ($categoryMapping !== null) {
                                    $collection->add($categoryMapping);
                                }
                            }
                        }
                    }
                }
            }
        }

        $productSW->setCategories($collection);
    }

    protected function prepareInvisibilityAssociatedData(ProductModel $product, ArticleSW &$productSW)
    {
        // Invisibility
        $collection = new ArrayCollection();
        foreach ($product->getInvisibilities() as $invisibility) {
            $customerGroupSW = CustomerGroupUtil::get(intval($invisibility->getCustomerGroupId()->getEndpoint()));
            if ($customerGroupSW === null) {
                $customerGroupSW = CustomerGroupUtil::get(Shopware()->Shop()->getCustomerGroup()->getId());
            }

            if ($customerGroupSW) {
                $collection->add($customerGroupSW);
            }
        }

        $productSW->setCustomerGroups($collection);
    }

    protected function prepareTaxAssociatedData(ProductModel $product, ArticleSW &$productSW)
    {
        // Tax
        $taxSW = Shopware()->Models()->getRepository('Shopware\Models\Tax\Tax')->findOneBy(array('tax' => $product->getVat()));
        if ($taxSW) {
            $productSW->setTax($taxSW);
        } else {
            throw new DatabaseException(sprintf('Could not find any Tax entity for value (%s)', $product->getVat()));
        }
    }

    protected function prepareManufacturerAssociatedData(ProductModel $product, ArticleSW &$productSW)
    {
        // Manufacturer
        $manufacturerMapper = Mmc::getMapper('Manufacturer');
        $manufacturerSW = $manufacturerMapper->find((int) $product->getManufacturerId()->getEndpoint());
        if ($manufacturerSW) {
            $productSW->setSupplier($manufacturerSW);
        } else {
            // Work Around - load dummy manufacturer
            $manufacturerSW = $manufacturerMapper->findOneBy(array('name' => '_'));
            
            if ($manufacturerSW === null) {
                $manufacturerSW = new \Shopware\Models\Article\Supplier();
                $manufacturerSW->setName('_')
                    ->setLink('');
                    
                $manufacturerSW->setDescription('');
                $manufacturerSW->setMetaTitle('');
                $manufacturerSW->setMetaDescription('');
                $manufacturerSW->setMetaKeywords('');
                
                $this->Manager()->persist($manufacturerSW);
            }
            
            $productSW->setSupplier($manufacturerSW);
        }
    }

    protected function prepareSpecialPriceAssociatedData(ProductModel $product, ArticleSW &$productSW)
    {
        // ProductSpecialPrice
        if (is_array($product->getSpecialPrices())) {
            foreach ($product->getSpecialPrices() as $i => $productSpecialPrice) {
                if (count($productSpecialPrice->getItems()) == 0) {
                    continue;
                }

                $collection = array();
                $priceGroupSW = Shopware()->Models()->getRepository('Shopware\Models\Price\Group')->find(intval($productSpecialPrice->getId()->getEndpoint()));
                if ($priceGroupSW === null) {
                    $priceGroupSW = new \Shopware\Models\Price\Group();
                    $this->Manager()->persist($priceGroupSW);
                }

                // SpecialPrice
                foreach ($productSpecialPrice->getItems() as $specialPrice) {
                    $customerGroupSW = CustomerGroupUtil::get(intval($specialPrice->getCustomerGroupId()->getEndpoint()));
                    if ($customerGroupSW === null) {
                        $customerGroupSW = CustomerGroupUtil::get(Shopware()->Shop()->getCustomerGroup()->getId());
                    }

                    $price = null;
                    $priceCount = count($product->getPrices());
                    if ($priceCount == 1) {
                        $price = reset($product->getPrices());
                    } elseif ($priceCount > 1) {
                        foreach ($product->getPrices() as $productPrice) {
                            if ($customerGroupSW->getId() == intval($productPrice->getCustomerGroupId()->getEndpoint())) {
                                $price = $productPrice->getNetPrice();

                                break;
                            }
                        }
                    }

                    if ($price === null) {
                        Logger::write(sprintf('Could not find any price for customer group (%s)', $specialPrice->getCustomerGroupId()->getEndpoint()), Logger::WARNING, 'database');

                        continue;
                    }
                    
                    $priceDiscountSW = Shopware()->Models()->getRepository('Shopware\Models\Price\Discount')->findOneBy(array('groupId' => $specialPrice->getProductSpecialPriceId()->getEndpoint()));
                    if ($priceDiscountSW === null) {
                        $priceDiscountSW = new \Shopware\Models\Price\Discount();
                        $this->Manager()->persist($priceDiscountSW);
                    }

                    $discountValue = 100 - (($specialPrice->getPriceNet() / $price) * 100);

                    $priceDiscountSW->setCustomerGroup($customerGroupSW)
                        ->setDiscount($discountValue)
                        ->setStart(1);

                    $this->Manager()->persist($priceDiscountSW);

                    $collection[] = $priceDiscountSW;
                }

                $this->Manager()->persist($priceGroupSW);

                $priceGroupSW->setName("Standard_{$i}")
                    ->setDiscounts($collection);

                $productSW->setPriceGroup($priceGroupSW)
                    ->setPriceGroupActive(1);
            }
        }
    }

    protected function prepareDetailAssociatedData(ProductModel $product, ArticleSW &$productSW, DetailSW &$detailSW = null, $isChild = false)
    {
        // Detail
        if ($detailSW === null) {
            $detailSW = new DetailSW();
            //$this->Manager()->persist($detailSW);
        }

        $helper = ProductNameHelper::build($product);
        $detailSW->setAdditionalText($helper->getAdditionalName());

        $kind = ($isChild && $detailSW->getId() > 0 && $productSW->getMainDetail() !== null && $productSW->getMainDetail()->getId() == $detailSW->getId()) ? 1 : 2;
        $active = $product->getIsActive();
        if (!$isChild) {
            $kind = $this->isParent($product) ? 0 : 1;
            $active = $this->isParent($product) ? false : $active;
        }

        //$kind = $isChild ? 2 : 1;
        $detailSW->setSupplierNumber($product->getManufacturerNumber())
            ->setNumber($product->getSku())
            ->setActive($active)
            ->setKind($kind)
            ->setStockMin(0)
            ->setPosition($product->getSort())
            ->setWeight($product->getProductWeight())
            ->setInStock($product->getStockLevel()->getStockLevel())
            ->setStockMin($product->getMinimumQuantity())
            ->setMinPurchase($product->getMinimumOrderQuantity())
            ->setReleaseDate($product->getAvailableFrom())
            ->setEan($product->getEan());

        $detailSW->setWidth($product->getWidth());
        $detailSW->setLen($product->getLength());
        $detailSW->setHeight($product->getHeight());

        // Delivery time
        if ($product->getSupplierDeliveryTime() > 0) {
            $detailSW->setShippingTime($product->getSupplierDeliveryTime());
        } else {
            foreach ($product->getI18ns() as $i18n) {
                if ($i18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                    $days = trim(str_replace(['Tage', 'Days', 'Tag', 'Day'], '', $i18n->getDeliveryStatus()));
                    if (strlen($days) > 0 && $days !== '0') {
                        $detailSW->setShippingTime($days);
                    }
                }
            }
        }

        // Base Price
        $detailSW->setReferenceUnit(0.0);
        $detailSW->setPurchaseUnit($product->getMeasurementQuantity());
        if ($product->getBasePriceDivisor() > 0 && $product->getMeasurementQuantity() > 0) {
            $detailSW->setReferenceUnit(($product->getMeasurementQuantity() / $product->getBasePriceDivisor()));
        }
        //$detailSW->setReferenceUnit($product->getBasePriceQuantity());
        //$detailSW->setPurchaseUnit($product->getMeasurementQuantity());

        $detailSW->setWeight($product->getProductWeight())
            ->setPurchaseSteps($product->getPackagingQuantity())
            ->setArticle($productSW);
    }

    protected function prepareDetailVariationAssociatedData(ProductModel &$product, DetailSW &$detailSW)
    {
        $groupMapper = Mmc::getMapper('ConfiguratorGroup');
        $optionMapper = Mmc::getMapper('ConfiguratorOption');
        foreach ($product->getVariations() as $variation) {
            $variationName = null;
            foreach ($variation->getI18ns() as $variationI18n) {
                if ($variationI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                    $variationName = $variationI18n->getName();
                }
            }

            $groupSW = $groupMapper->findOneBy(array('name' => $variationName));
            if ($groupSW !== null) {
                foreach ($variation->getValues() as $variationValue) {
                    $name = null;
                    foreach ($variationValue->getI18ns() as $variationValueI18n) {
                        if ($variationValueI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                            $name = $variationValueI18n->getName();
                        }
                    }

                    if ($name === null) {
                        continue;
                    }

                    $optionSW = $optionMapper->findOneBy(array('name' => $name, 'groupId' => $groupSW->getId()));

                    if ($optionSW === null) {
                        continue;
                    }

                    $sql = "DELETE FROM s_article_configurator_option_relations WHERE article_id = ? AND option_id = ?";
                    Shopware()->Db()->query($sql, array($detailSW->getId(), $optionSW->getId()));

                    $sql = "INSERT INTO s_article_configurator_option_relations (id, article_id, option_id) VALUES (NULL, ?, ?)";
                    Shopware()->Db()->query($sql, array($detailSW->getId(), $optionSW->getId()));
                }
            }
        }
    }

    protected function prepareAttributeAssociatedData(ProductModel $product, ArticleSW &$productSW, DetailSW &$detailSW, array &$attrMappings, $isChild = false)
    {
        // Attribute
        $attributeSW = $detailSW->getAttribute();
        if ($attributeSW === null) {
            $attributeSW = new \Shopware\Models\Attribute\Article();
            $attributeSW->setArticle($productSW);
            $attributeSW->setArticleDetail($detailSW);

            $this->Manager()->persist($attributeSW);
        }

        // Reset
        for ($i = 1; $i <= 20; $i++) {
            $setter = "setAttr{$i}";
            $attributeSW->{$setter}(null);
        }

        $i = 3;
        $attrMappings = [];
        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute->getIsCustomProperty()) {
                $i++;
                foreach ($attribute->getI18ns() as $attributeI18n) {
                    if ($attributeI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {

                        // Work Around, thx @db structure
                        if ($i == 17) {
                            $i++;
                        }

                        // active
                        if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::IS_ACTIVE)) {
                            $isActive = (strtolower($attributeI18n->getValue()) === 'false'
                                || strtolower($attributeI18n->getValue()) === '0') ? 0 : 1;
                            if ($isChild) {
                                $detailSW->setActive((int) $isActive);
                            } else {
                                $productSW->setActive((int) $isActive);
                            }

                            continue;
                        }

                        // Notification
                        if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::SEND_NOTIFICATION)) {
                            $notification = (strtolower($attributeI18n->getValue()) === 'false'
                                || strtolower($attributeI18n->getValue()) === '0') ? 0 : 1;

                            $productSW->setNotification($notification);

                            continue;
                        }

                        // Shipping free
                        if (strtolower($attributeI18n->getName()) === strtolower(ProductAttr::SHIPPING_FREE)) {
                            $shippingFree = (strtolower($attributeI18n->getValue()) === 'false'
                                || strtolower($attributeI18n->getValue()) === '0') ? 0 : 1;

                            $detailSW->setShippingFree($shippingFree);

                            continue;
                        }

                        $setter = "setAttr{$i}";

                        if (preg_match('/attr(20|1[0-9]{1}|[1-9]{1})/', $attributeI18n->getName(), $matches)) {
                            if (strlen($matches[0]) == strlen($attributeI18n->getName())) {
                                $number = str_replace('attr', '', $attributeI18n->getName());
                                $s_setter = "setAttr{$number}";
                                $s_getter = "getAttr{$number}";
                                if (method_exists($attributeSW, $s_setter)) {
                                    $oldValue = $attributeSW->{$s_getter}();
                                    $attributeSW->{$s_setter}($attributeI18n->getValue());

                                    if ($oldValue !== null && $number != $i && method_exists($attributeSW, $setter)) {
                                        $attributeSW->{$setter}($oldValue);
                                        $hostId = $attrMappings[$number];
                                        $attrMappings[$i] = $hostId;
                                    } elseif ($number < $i && $i > 4) {
                                        $i--;
                                    }

                                    $attrMappings[$number] = $attribute->getId()->getHost();

                                    continue;
                                }
                            }
                        }

                        if (method_exists($attributeSW, $setter)) {
                            $attributeSW->{$setter}($attributeI18n->getValue());
                            $attrMappings[$i] = $attribute->getId()->getHost();
                        }
                    }
                }
            }
        }

        $this->Manager()->persist($attributeSW);

        $detailSW->setAttribute($attributeSW);
        $productSW->setAttribute($attributeSW);
    }

    protected function hasVariationChanges(ProductModel &$product)
    {
        if (count($product->getVariations()) > 0) {
            if (strlen($product->getId()->getEndpoint()) > 0 && IdConcatenator::isProductId($product->getId()->getEndpoint())) {
                $checksum = ChecksumLinker::find($product, ProductChecksum::TYPE_VARIATION);
                if ($checksum === null) {
                    return false;
                }
                
                return $checksum->hasChanged();
            } else {
                return true;
            }
        }

        return false;
    }

    protected function prepareVariationAssociatedData(ProductModel $product, ArticleSW &$productSW)
    {
        // Variations
        if ($this->hasVariationChanges($product)) {
            $confiSet = $productSW->getConfiguratorSet();

            $groups = array();
            $options = array();

            if (!$confiSet) {
                $confiSet = new \Shopware\Models\Article\Configurator\Set();
                $confiSet->setName('Set-' . $product->getSku());
                $this->Manager()->persist($confiSet);
            }

            $groupMapper = Mmc::getMapper('ConfiguratorGroup');
            $optionMapper = Mmc::getMapper('ConfiguratorOption');
            $types = array();
            foreach ($product->getVariations() as $variation) {

                if (strlen(trim($variation->getType())) > 0) {
                    if (!isset($types[$variation->getType()])) {
                        $types[$variation->getType()] = 0;
                    }

                    $types[$variation->getType()]++;
                }

                $variationName = null;
                $variationValueName = null;
                foreach ($variation->getI18ns() as $variationI18n) {
                    if ($variationI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                        $variationName = $variationI18n->getName();
                    }
                }

                $groupSW = $groupMapper->findOneBy(array('name' => $variationName));
                if ($groupSW === null) {
                    $groupSW = new \Shopware\Models\Article\Configurator\Group();
                }

                $groupSW->setName($variationName);
                $groupSW->setDescription('');
                //$groupSW->setPosition(0);
                $groupSW->setPosition($variation->getSort());

                $this->Manager()->persist($groupSW);

                //$groups->add($groupSW);
                $groups[] = $groupSW;

                foreach ($variation->getValues() as $i => $variationValue) {
                    foreach ($variationValue->getI18ns() as $variationValueI18n) {
                        if ($variationValueI18n->getLanguageISO() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                            $variationValueName = $variationValueI18n->getName();
                        }
                    }

                    $optionSW = null;
                    if ($groupSW->getId() > 0) {
                        $optionSW = $optionMapper->findOneBy(array('name' => $variationValueName, 'groupId' => $groupSW->getId()));
                    }

                    if ($optionSW === null) {
                        $optionSW = new \Shopware\Models\Article\Configurator\Option();
                    }

                    $optionSW->setName($variationValueName);
                    //$optionSW->setPosition(($i + 1));
                    $optionSW->setPosition($variationValue->getSort());
                    $optionSW->setGroup($groupSW);

                    $this->Manager()->persist($optionSW);

                    //$options->add($optionSW);
                    $options[] = $optionSW;
                }
            }

            $confiSet->setOptions($options)
                ->setGroups($groups)
                ->setType($this->calcVariationType($types));

            $this->Manager()->persist($confiSet);

            $productSW->setConfiguratorSet($confiSet);
        }
    }

    protected function calcVariationType(array $types)
    {
        if (count($types) == 0) {
            return ProductVariation::TYPE_RADIO;
        }

        arsort($types);

        $checkEven = function($vTypes) {
            if (count($vTypes) > 1) {
                $arr = array_values($vTypes);
                return ($arr[0] == $arr[1]);
            }

            return false;
        };

        reset($types);
        $key = $checkEven($types) ? ProductVariation::TYPE_RADIO : key($types);

        return VariationType::map($key);
    }

    protected function preparePriceAssociatedData(ProductModel $product, ArticleSW &$productSW, DetailSW &$detailSW)
    {
        // fix
        /*
        $recommendedRetailPrice = 0.0;
        if ($product->getRecommendedRetailPrice() > 0.0) {
            $recommendedRetailPrice = Money::AsNet($recommendedRetailPrice, $product->getVat());
        }
        */

        $collection = ProductPriceMapper::buildCollection(
            $product->getPrices(),
            $productSW,
            $detailSW,
            $product->getRecommendedRetailPrice(),
            $product->getPurchasePrice()
        );

        if (count($collection) > 0) {
            $detailSW->setPrices($collection);
        }
    }

    protected function prepareSpecificAssociatedData(ProductModel $product, ArticleSW &$productSW, DetailSW $detailSW)
    {
        $group = $productSW->getPropertyGroup();
        $collection = new ArrayCollection();

        if (count($product->getSpecifics()) > 0) {
            if ($group === null) {
                $group = new \Shopware\Models\Property\Group();
                $group->setName($product->getSku())
                    ->setPosition(0)
                    ->setComparable(1)
                    ->setSortMode(0);

                $this->Manager()->persist($group);
            }

            $mapper = Mmc::getMapper('Specific');
            $group->setOptions(array());
            $options = array();
            foreach ($product->getSpecifics() as $productSpecific) {
                $valueSW = $mapper->findValue((int) $productSpecific->getSpecificValueId()->getEndpoint());
                if ($valueSW !== null) {
                    $collection->add($valueSW);
                    if (!in_array($valueSW->getOption()->getId(), $options)) {
                        $group->addOption($valueSW->getOption());
                        $options[] = $valueSW->getOption()->getId();
                    }
                }
            }

            $this->Manager()->persist($group);
        }

        $productSW->setPropertyValues($collection);
        $productSW->setPropertyGroup($group);
    }

    protected function saveTranslationData(ProductModel $product, ArticleSW $productSW, array $attrMappings)
    {
        $shopMapper = Mmc::getMapper('Shop');

        // AttributeI18n
        $attrI18ns = [];
        foreach ($product->getAttributes() as $attr) {
            foreach ($attr->getI18ns() as $attrI18n) {
                if ($attrI18n->getLanguageISO() !== LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                    if (!isset($attrI18ns[$attrI18n->getLanguageISO()])) {
                        $attrI18ns[$attrI18n->getLanguageISO()] = [];
                    }

                    if (($index = array_search($attr->getId()->getHost(), $attrMappings)) !== false) {
                        $i = "attr{$index}";
                        $attrI18ns[$attrI18n->getLanguageISO()][$i] = $attrI18n->getValue();
                    }
                }
            }
        }

        // ProductI18n
        $translationUtil = new TranslationUtil();
        $cache = array();
        foreach ($product->getI18ns() as $i18n) {
            $locale = LocaleUtil::getByKey(LanguageUtil::map(null, null, $i18n->getLanguageISO()));

            if ($locale === null) {
                Logger::write(sprintf('Could not find any locale for (%s)', $i18n->getLanguageISO()), Logger::WARNING, 'database');

                continue;
            }

            if ($i18n->getLanguageISO() !== LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                $shops = $shopMapper->findByLocale($locale->getLocale());

                if ($shops !== null && is_array($shops) && count($shops) > 0) {
                    $helper = ProductNameHelper::build($product, $i18n->getLanguageISO());

                    foreach ($shops as $shop) {
                        $cache[$shop->getId()] = array(
                            'name' => $helper->getProductName(),
                            'descriptionLong' => $i18n->getDescription(),
                            'metaTitle' => $i18n->getTitleTag(),
                            'description' => $i18n->getMetaDescription(),
                            'keywords' => $i18n->getMetaKeywords(),
                            'packUnit' => ''
                        );

                        if (isset($attrI18ns[$i18n->getLanguageISO()])) {
                            $cache[$shop->getId()] = array_merge($cache[$shop->getId()], $attrI18ns[$i18n->getLanguageISO()]);
                        }

                        $translationUtil->write(
                            $shop->getId(),
                            'article',
                            $productSW->getId(),
                            $cache[$shop->getId()]
                        );
                    }
                } else {
                    Logger::write(sprintf('Could not find any shop with locale (%s) and iso (%s)', $locale->getLocale(), $i18n->getLanguageISO()), Logger::WARNING, 'database');

                    continue;
                }
            }
        }

        // Unit
        if ($product->getUnitId()->getHost() > 0) {
            $unitMapper = Mmc::getMapper('Unit');
            $unitSW = $unitMapper->findOneBy(array('hostId' => $product->getUnitId()->getHost()));
            if ($unitSW !== null) {
                foreach ($unitSW->getI18ns() as $unitI18n) {
                    if ($unitI18n->getLanguageIso() !== LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                        $locale = LocaleUtil::getByKey(LanguageUtil::map(null, null, $unitI18n->getLanguageIso()));

                        if ($locale === null) {
                            Logger::write(sprintf('Could not find any locale for (%s)', $unitI18n->getLanguageIso()), Logger::WARNING, 'database');

                            continue;
                        }

                        $shops = $shopMapper->findByLocale($locale->getLocale());

                        if ($shops !== null && is_array($shops) && count($shops) > 0) {
                            foreach ($shops as $shop) {
                                $data = array();
                                if (array_key_exists($shop->getId(), $cache)) {
                                    $data = $cache[$shop->getId()];
                                } else {
                                    $data = $translationUtil->read(
                                        $shop->getId(),
                                        'article',
                                        $productSW->getId()
                                    );
                                }

                                $data['packUnit'] = $unitI18n->getName();

                                $translationUtil->write(
                                    $shop->getId(),
                                    'article',
                                    $productSW->getId(),
                                    $data
                                );
                            }
                        } else {
                            Logger::write(sprintf('Could not find any shop with locale (%s) and iso (%s)', $locale->getLocale(), $unitI18n->getLanguageISO()), Logger::WARNING, 'database');

                            continue;
                        }
                    }
                }
            }
        }
    }

    protected function saveVariationTranslationData(ProductModel $product, ArticleSW &$productSW)
    {
        $groupMapper = Mmc::getMapper('ConfiguratorGroup');
        $optionMapper = Mmc::getMapper('ConfiguratorOption');
        $confiSetSW = $productSW->getConfiguratorSet();
        if ($confiSetSW !== null && count($product->getVariations()) > 0) {

            // Get default translation values
            $variations = array();
            $values = array();
            $defaultIso = LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale());
            foreach ($product->getVariations() as $variation) {
                foreach ($variation->getI18ns() as $variationI18n) {
                    if ($variationI18n->getLanguageISO() === $defaultIso) {
                        $variations[$variationI18n->getName()] = $variation->getId()->getHost();
                        break;
                    }
                }

                foreach ($variation->getValues() as $value) {
                    foreach ($value->getI18ns() as $valueI18n) {
                        if ($valueI18n->getLanguageISO() === $defaultIso) {
                            $values[$variation->getId()->getHost()][$valueI18n->getName()] = $value->getId()->getHost();
                            break;
                        }
                    }
                }
            }

            // Write non default translation values
            foreach ($product->getVariations() as $variation) {
                foreach ($variation->getI18ns() as $variationI18n) {
                    if ($variationI18n->getLanguageISO() !== $defaultIso) {
                        foreach ($confiSetSW->getGroups() as $groupSW) {
                            if (isset($variations[$groupSW->getName()]) && $variations[$groupSW->getName()] == $variation->getId()->getHost()) {
                                try {
                                    $groupMapper->saveTranslatation($groupSW->getId(), $variationI18n->getLanguageISO(), $variationI18n->getName());
                                } catch (\Exception $e) {
                                    Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                                }
                            }
                        }
                    }
                }

                foreach ($variation->getValues() as $value) {
                    foreach ($value->getI18ns() as $valueI18n) {
                        if ($valueI18n->getLanguageISO() !== $defaultIso) {
                            foreach ($confiSetSW->getOptions() as $optionSW) {
                                if (isset($values[$variation->getId()->getHost()][$optionSW->getName()])
                                    && $values[$variation->getId()->getHost()][$optionSW->getName()] == $value->getId()->getHost()) {

                                    try {
                                        $optionMapper->saveTranslatation($optionSW->getId(), $valueI18n->getLanguageISO(), $valueI18n->getName());
                                    } catch (\Exception $e) {
                                        Logger::write(ExceptionFormatter::format($e), Logger::ERROR, 'database');
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    protected function prepareSetVariationRelations(ProductModel $product, ArticleSW &$productSW)
    {
        if (!$this->hasVariationChanges($product)) {
            return;
        }

        $confiSet = $productSW->getConfiguratorSet();

        $sql = "DELETE FROM s_article_configurator_set_group_relations WHERE set_id = ?";
        Shopware()->Db()->query($sql, array($confiSet->getId()));

        $sql = "DELETE FROM s_article_configurator_set_option_relations WHERE set_id = ?";
        Shopware()->Db()->query($sql, array($confiSet->getId()));

        // Groups
        foreach ($confiSet->getGroups() as $groupSW) {
            $sql = "INSERT INTO s_article_configurator_set_group_relations (set_id, group_id) VALUES (?,?)";
            Shopware()->Db()->query($sql, array($confiSet->getId(), $groupSW->getId()));
        }

        // Options            
        foreach ($confiSet->getOptions() as $optionSW) {
            $sql = "INSERT INTO s_article_configurator_set_option_relations (set_id, option_id) VALUES (?,?)";
            Shopware()->Db()->query($sql, array($confiSet->getId(), $optionSW->getId()));
        }
    }

    protected function prepareUnitAssociatedData(ProductModel $product, DetailSW &$detailSW = null)
    {
        if ($product->getUnitId()->getHost() > 0) {
            $unitMapper = Mmc::getMapper('Unit');
            $unitSW = $unitMapper->findOneBy(array('hostId' => $product->getUnitId()->getHost()));
            if ($unitSW !== null) {
                foreach ($unitSW->getI18ns() as $unitI18n) {
                    if ($unitI18n->getLanguageIso() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                        $detailSW->setPackUnit($unitI18n->getName());
                    }
                }
            }
        }
    }

    protected function prepareMeasurementUnitAssociatedData(ProductModel $product, DetailSW &$detailSW = null)
    {
        if (strlen($product->getMeasurementUnitCode()) > 0) {
            $measurementUnitMapper = Mmc::getMapper('MeasurementUnit');
            $measurementUnitSW = $measurementUnitMapper->findOneBy(array('unit' => $product->getMeasurementUnitCode()));
            if ($measurementUnitSW !== null) {
                $detailSW->setUnit($measurementUnitSW);
            }
        }
    }

    protected function prepareMediaFileAssociatedData(ProductModel $product, ArticleSW &$productSW)
    {
        $linkCollection = array();
        $downloadCollection = array();

        foreach ($product->getMediaFiles() as $mediaFile) {
            $name = '';
            foreach ($mediaFile->getI18ns() as $i18n) {
                if ($i18n->getLanguageIso() === LanguageUtil::map(Shopware()->Shop()->getLocale()->getLocale())) {
                    $name = $i18n->getName();
                }
            }

            if (preg_match('/^http|ftp{1}/i', $mediaFile->getUrl())) {
                $linkSW = new LinkSW();
                $linkSW->setLink($mediaFile->getUrl())
                    ->setName($name);

                $this->Manager()->persist($linkSW);
                $linkCollection[] = $linkSW;
            } else {
                $downloadSW = new DownloadSW();
                $downloadSW->setFile($mediaFile->getUrl())
                    ->setSize(0)
                    ->setName($name);

                $this->Manager()->persist($downloadSW);
                $downloadCollection[] = $downloadSW;
            }
        }

        $productSW->setLinks($linkCollection);
        $productSW->setDownloads($downloadCollection);
    }

    protected function deleteTranslationData(ArticleSW $productSW)
    {
        $translationUtil = new TranslationUtil();
        $translationUtil->delete('article', $productSW->getId());
    }

    protected function deleteProductData(ProductModel $product)
    {
        $productId = (strlen($product->getId()->getEndpoint()) > 0) ? $product->getId()->getEndpoint() : null;

        /*
        Logger::write(sprintf('>>> Product with id (%s, %s), masterProductId (%s, %s), manufacturerId (%s, %s)',
            $product->getId()->getEndpoint(),
            $product->getId()->getHost(),
            $product->getMasterProductId()->getEndpoint(),
            $product->getMasterProductId()->getHost(),
            $product->getManufacturerId()->getEndpoint(),
            $product->getManufacturerId()->getHost()
        ), Logger::DEBUG, 'database');
        */

        if ($productId !== null) {
            list($detailId, $id) = IdConcatenator::unlink($productId);
            $detailSW = $this->findDetail((int) $detailId);
            if ($detailSW === null) {
                //throw new DatabaseException(sprintf('Detail (%s) not found', $detailId));
                Logger::write(sprintf('Detail with id (%s, %s) not found',
                    $product->getId()->getEndpoint(),
                    $product->getId()->getHost()
                ), Logger::ERROR, 'database');
                return;
            }

            $productSW = $this->find((int) $id);
            if ($productSW === null) {
                Logger::write(sprintf('Product with id (%s, %s) not found',
                    $product->getId()->getEndpoint(),
                    $product->getId()->getHost()
                ), Logger::ERROR, 'database');
                return;
            }

            $mainDetailId = Shopware()->Db()->fetchOne(
                'SELECT main_detail_id FROM s_articles WHERE id = ?',
                array($productSW->getId())
            );

            $sql = 'DELETE FROM s_article_configurator_option_relations WHERE article_id = ?';
            Shopware()->Db()->query($sql, array($detailSW->getId()));

            if ($this->isChildSW($productSW, $detailSW)) {
                //Shopware()->Db()->delete('s_articles_attributes', array('articledetailsID = ?' => $detailSW->getId()));

                try {
                    Shopware()->Db()->delete('s_articles_attributes', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_prices', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_details', array('id = ?' => $detailSW->getId()));

                    if ($mainDetailId == $detailSW->getId()) {
                        $count = Shopware()->Db()->fetchOne(
                            'SELECT count(*) FROM s_articles_details WHERE articleID = ?',
                            array($productSW->getId())
                        );

                        $kindSql = ($count > 1) ? ' AND kind != 0 ' : '';

                        Shopware()->Db()->query(
                            'UPDATE s_articles SET main_detail_id = (SELECT id FROM s_articles_details WHERE articleID = ? ' . $kindSql . ' LIMIT 1) WHERE id = ?',
                            array($productSW->getId(), $productSW->getId())
                        );

                        /*
                        $sql = '
                            INSERT INTO s_articles_attributes (id, articleID, articledetailsID)
                              SELECT null, ?, main_detail_id
                              FROM s_articles
                              WHERE id = ?
                        ';

                        Shopware()->Db()->query($sql, array($productSW->getId(), $productSW->getId()));
                        */
                    }

                    /*
                    $this->Manager()->remove($detailSW->getAttribute());
                    $this->Manager()->remove($detailSW);
                    $this->Manager()->flush();
                    */

                    /*
                    Logger::write(sprintf('>>>> DELETING DETAIL with id (%s, %s)',
                        $product->getId()->getEndpoint(),
                        $product->getId()->getHost()
                    ), Logger::DEBUG, 'database');
                    */

                    /*
                    if ($productSW !== null && $mainDetailId == $detailSW->getId()) {
                        $mainDetailSW = $this->findDetailBy(array('articleId' => $productSW->getId()));

                        if ($mainDetailSW !== null && $mainDetailSW->getKind() != 0) {
                            $attributeSW = $mainDetailSW->getAttribute();
                            if ($attributeSW === null) {
                                $attributeSW = new \Shopware\Models\Attribute\Article();
                                $attributeSW->setArticle($productSW);
                                $attributeSW->setArticleDetail($mainDetailSW);

                                $this->Manager()->persist($attributeSW);
                            }

                            $productSW->setAttribute($attributeSW);
                            $mainDetailSW->setAttribute($attributeSW);
                            $productSW->setMainDetail($mainDetailSW);

                            $this->Manager()->persist($productSW);
                            $this->Manager()->flush();
                        }
                    }
                    */
                } catch (\Exception $e) {
                    Logger::write('DETAIL ' . ExceptionFormatter::format($e), Logger::ERROR, 'database');
                }
            } elseif ($productSW !== null) {
                try {
                    $this->deleteTranslationData($productSW);

                    $set = $productSW->getConfiguratorSet();
                    if ($set !== null) {
                        $this->Manager()->remove($set);
                    }

                    Shopware()->Db()->delete('s_articles_attributes', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_prices', array('articledetailsID = ?' => $detailSW->getId()));
                    Shopware()->Db()->delete('s_articles_details', array('id = ?' => $detailSW->getId()));
                    Shopware()->Db()->query(
                        'DELETE f, r
                            FROM s_filter f
                            LEFT JOIN s_filter_relations r ON r.groupID = f.id
                            WHERE f.name = ?',
                        array($detailSW->getNumber())
                    );
                    Shopware()->Db()->delete('s_filter_articles', array('articleID = ?' => $productSW->getId()));

                    $this->Manager()->remove($productSW);
                    $this->Manager()->flush($productSW);

                    /*
                    Logger::write(sprintf('>>>> DELETING PARENT with id (%s, %s)',
                        $product->getId()->getEndpoint(),
                        $product->getId()->getHost()
                    ), Logger::DEBUG, 'database');
                    */
                } catch (\Exception $e) {
                    Logger::write('PARENT ' . ExceptionFormatter::format($e), Logger::ERROR, 'database');
                }
            }
        }
    }

    public function isChild(ProductModel $product)
    {
        //return (strlen($product->getId()->getEndpoint()) > 0 && strpos($product->getId()->getEndpoint(), '_') !== false);
        //return (!$product->getIsMasterProduct() && count($product->getVariations()) > 0 && $product->getMasterProductId()->getHost() > 0);
        return (!$product->getIsMasterProduct() && $product->getMasterProductId()->getHost() > 0);
    }

    public function isParent(ProductModel $product)
    {
        //return ($product->getIsMasterProduct() && count($product->getVariations()) > 0 && $product->getMasterProductId()->getHost() == 0);
        return ($product->getIsMasterProduct() && $product->getMasterProductId()->getHost() == 0);
    }

    public function isChildSW(ArticleSW $productSW = null, DetailSW $detailSW)
    {
        // If the parent is already deleted or a configurator set is present
        if ($productSW === null || ($productSW->getConfiguratorSet() !== null && $productSW->getConfiguratorSet()->getId() > 0)) {
            return ((int) $detailSW->getKind() != 0) ? true : false;
        }

        return false;
    }
}
