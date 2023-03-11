<?



/*
Это файл init.php на том же сайте, где было добавление в корзину фиктивных свойств.
В целом, это набор разных эвентов, которые немного модифицируют оформленный заказ или отредактированный товар + тут есть пара событий которые висят на кроне.
Файл большой, и я, упаси бог, не прошу вас вчитываться в написанный ужас, но тут есть реализация системы бонусных баллов(Точнее их подсчёта),
реализация пересчёта стоимость доставки(на самом деле костыль, который заказчик требовал непонятно зачем, ну ладно, обезьяне сказали - обезьяна сделала)
не знаю, что ещё тут написать, в целом я это показываю чтобы вы увидели что я умею читать документацию и писать обезьяний код
*/

if (file_exists(__DIR__ . '/classes/GetSettings.php'))
    require_once(__DIR__ . '/classes/GetSettings.php');

use \Bitrix\Main\EventManager;
$eventManager = \Bitrix\Main\EventManager::getInstance();

$eventManager->addEventHandler("sale", "OnSaleOrderSaved", ['OrderEvents', 'OrderServicesCollection']);

class OrderEvents {
	public static function OrderServicesCollection(\Bitrix\Main\Event $event) {
		$order = $event->getParameter('ENTITY');
		$isNew = $event->getParameter("IS_NEW");
		$shipmentCollection = $order->getShipmentCollection();
		$servicesPrice = 0;
		$res = CIBlock::GetProperties(28, Array(), Array("CODE"=>"SERVICES_COLLECTION"));
		if($res_arr = $res->Fetch()) {
			$servicesPrice = $res_arr["DEFAULT_VALUE"];
		}
		if($servicesPrice && $isNew) {
			$basket = $order->getBasket();
			$basketItems = $basket->getBasketItems();
			$deliveryPrice = $order->getField("PRICE_DELIVERY");
			$servicesPriceFinal = $deliveryPrice + ($servicesPrice * count($basketItems));
			foreach($shipmentCollection as $shipment) {
				if(!$shipment->isSystem()) {
					$shipment->setBasePriceDelivery($servicesPriceFinal, false);
					$order->save();
				}
			}
		}
	}
}

AddEventHandler("search", "BeforeIndex", "BeforeIndexHandler");
function BeforeIndexHandler($arFields) {
    $arrIblock = array(28);
    $arDelFields = array("DETAIL_TEXT" , "PREVIEW_TEXT");
    if (CModule::IncludeModule('iblock') && $arFields["MODULE_ID"] == 'iblock' && in_array($arFields["PARAM2"], $arrIblock) && intval($arFields["ITEM_ID"]) > 0){
        $dbElement = CIblockElement::GetByID($arFields["ITEM_ID"]);
        if ($arElement = $dbElement->Fetch()){
            foreach ($arDelFields as $value) {
                if (isset ($arElement[$value]) && strlen($arElement[$value])> 0){
                    $arFields["BODY"] = str_replace ($arElement[$value], "", $arFields["BODY"]);
                }
            }
        }
        return $arFields;
    }
}

AddEventHandler("sale", "OnBeforeBasketAdd", "OnBeforePresentToBasket");
function  OnBeforePresentToBasket(&$arFields)
{
	if(CModule::IncludeModule("iblock"))
	{
		$arrIblock = array(28);
        if($arFields['PRODUCT_ID']) {
            $arFilter = array("IBLOCK_ID" => $arrIblock, 'ID' => $arFields['PRODUCT_ID']);
            $arSelect = array('IBLOCK_ID',"PROPERTY_BONUSNYKH_BALLOV");
            $rsElement = CIBlockElement::GetList(array('SORT' => 'ASC'), $arFilter, false, false, $arSelect);
            if ($arElement = $rsElement->GetNext())
            {
                if($arElement["PROPERTY_BONUSNYKH_BALLOV_VALUE"]) {
                    $arFields['PROPS'][] = array(
                        'NAME'  => "Бонусных баллов:",
                        'CODE'  => "BONUSNYKH_BALLOV",
                        'VALUE' => $arElement["PROPERTY_BONUSNYKH_BALLOV_VALUE"],
                        'SORT'  => 0
                    );
                }
            }
        }
	}
	return;
}

AddEventHandler("iblock", "OnAfterIBlockElementUpdate", array("CashbackProduct", "AddCashbackProduct"));
AddEventHandler("iblock", "OnAfterIBlockElementAdd", array("CashbackProduct", "AddCashbackProduct"));

class CashbackProduct {

    public static $ID_CATALOG_DEFAULT = 28;

    function AddCashbackProduct(&$arFields) {

        if ($arFields["IBLOCK_ID"] == self::$ID_CATALOG_DEFAULT) {
            if(CModule::IncludeModule("iblock") && CModule::IncludeModule("catalog") && CModule::IncludeModule("sale")) {
                $PRODUCT_ID = $arFields["ID"];
                $PERSON_TYPE_ID = 3;
                $dbPrice = \CPrice::GetList(
                    array(),
                    array(
                        "PRODUCT_ID" => $PRODUCT_ID,
                        "CATALOG_GROUP_ID" => $PERSON_TYPE_ID
                    )
                );
                $arPrice = $dbPrice->Fetch();
                $price = $arPrice["PRICE"];
                $PROPERTY_CODE = "BONUSNYKH_BALLOV";
                $PROPERTY_CODE_UP_CASHBACK = "POVYSHENNYY_KESHBEK";
                $arCatalogFilter = array("IBLOCK_ID" => self::$ID_CATALOG_DEFAULT, 'ID' => $PRODUCT_ID);
                $arCatalogSelect = array('IBLOCK_ID',"PROPERTY_SKIDKA", "PROPERTY_".$PROPERTY_CODE, "PROPERTY_".$PROPERTY_CODE_UP_CASHBACK);
                $rsCatalogElement = CIBlockElement::GetList(array('SORT' => 'ASC'), $arCatalogFilter, false, false, $arCatalogSelect);
                if ($arCatalogElement = $rsCatalogElement->GetNext()) {
                    $upCashback = $arCatalogElement["PROPERTY_".$PROPERTY_CODE_UP_CASHBACK."_VALUE"];
                    if($arCatalogElement["PROPERTY_SKIDKA_VALUE"]) {
                        $price = round($price * (100 - intval($arCatalogElement["PROPERTY_SKIDKA_VALUE"])) / 100, 2);
                    }
                }
                $arSectionFilter = Array("IBLOCK_ID" => self::$ID_CATALOG_DEFAULT, "GLOBAL_ACTIVE" => 'Y', 'UF_CASHBACK_PERCENT');
                $rsSectionElement = CIBlockSection::GetList(array('SORT' => 'DESC'), $arSectionFilter, false, Array('UF_*'));
                while ($arSectionElement = $rsSectionElement->GetNext())
                {
                    if($arSectionElement['~UF_CASHBACK_PERCENT']) {
                        $PERCENT_CASHBACK[] = $arSectionElement['~UF_CASHBACK_PERCENT'];
                    }
                }
                if($upCashback) {
                    $PERCENT_CASHBACK = $upCashback;
                    $PROPERTY_VALUE = round($price * ($PERCENT_CASHBACK / 100));

                } else {
                    $PERCENT_CASHBACK = $PERCENT_CASHBACK[0];
                    $PROPERTY_VALUE = round($price * ($PERCENT_CASHBACK / 100));

                }
                if($PROPERTY_VALUE) {
                    CIBlockElement::SetPropertyValuesEx($PRODUCT_ID, false, array($PROPERTY_CODE => $PROPERTY_VALUE));
                }
            }
        }
    }
}
/* Само обновление по расписанию коэффициентов раз в три часа */
$eventManager->addEventHandler("catalog", "\Bitrix\Catalog\MeasureRatio::OnBeforeUpdate", function (\Bitrix\Main\Event $event) {
    $result = new \Bitrix\Main\Entity\EventResult;
    $data = $event->getParameter("fields");

    $arSelect = Array("ID","IBLOCK_ID", "PROPERTY_KOEFFITSIENT_FASOVKI");
    $arFilter = Array("ID" => $data["PRODUCT_ID"]);
    $res = CIBlockElement::GetList(Array(), $arFilter, false, Array("nPageSize"=>50), $arSelect);

    while($ob = $res->GetNextElement())
    {
        $arFields = $ob->GetProperties();
    }


    if ($arFields['KOEFFITSIENT_FASOVKI']['VALUE'] == '0' || empty($arFields['KOEFFITSIENT_FASOVKI']['VALUE'])) {

        $result->modifyFields(array('RATIO' => 1));
        AddMessage2Log('Ноль или пусто  -  Товар ' . $data["PRODUCT_ID"]);

    } else {

        $true_coef = str_replace(',','.',$arFields['KOEFFITSIENT_FASOVKI']['VALUE']);
        $result->modifyFields(array('RATIO' => $true_coef));
        AddMessage2Log($true_coef . ' Товар ' . $data["PRODUCT_ID"]);

    }


    return $result;
});
/* Старт обновления по расписание коэффициентов раз в три часа */
function updateProductsCoef() {
    if(CModule::IncludeModule('iblock')){
        $el = new CIBlockElement;
        $elFilter = array("IBLOCK_ID" => 28);
        $res = CIBlockElement::GetList(array("ID"=>"ASC"), $elFilter, false, false, array('ID', 'IBLOCK_ID'));
        while($ob = $res->GetNext()){
            $curElementRatio = CCatalogMeasureRatio::getList(
            Array(),
            array('IBLOCK_ID' => 28, 'PRODUCT_ID' => $ob["ID"]), false, false);
            if($arRatio = $curElementRatio->GetNext()){
                $ratioId = $arRatio['ID'];
                \Bitrix\Catalog\MeasureRatioTable::update($ratioId,array("PRODUCT_ID" => $ob["ID"]));
            }
        }

    }

    return "updateProductsCoef();";
}
?>