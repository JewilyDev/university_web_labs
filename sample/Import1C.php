<?php
namespace Picom\EventHelpers;

use Bitrix\Landing\Debug;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Highloadblock\HighloadBlockTable;




/*
Задача: Есть сайт , на который заливают очень необработанный прайс из 1С, который не подходит под штатную структуру битрикса.
Существует товар-болванка, куда из той же 1С-ки загружаются реквизиты, с помощью которых нужно связывать этот товар с его вариантами(у Битрикса это называется Торговые Предложения)
Код ниже осуществляет почти всё сопоставление по реквизитам, за исключением некоторых моментов привязки(Они вынесены в другой системыный файл по разным причинам)
Код выглядит как авгиевы конюшни, и работает долго, зато в сравнение с компанией, изначально разрабатывавшей сайт, скорость многократно возросла.
*/


class Import1C {

	function OnCompleteCatalogImport1C()
	{
		\Picom\EventHelpers\Import1C::updateRelations();
	}

	public static function updateRelations(){

		if (!\Bitrix\Main\Loader::IncludeModule("iblock")){
			return false;
		}
		$classCatalog = \Bitrix\Iblock\Iblock::wakeUp(CATALOG_IBLOCK_ID)->getEntityDataClass();
		$classSku = \Bitrix\Iblock\Iblock::wakeUp(CATALOG_SKU_IBLOCK_ID)->getEntityDataClass();

		$rsProperty = \Bitrix\Iblock\PropertyTable::getList(array(
			'filter' => array(
				'IBLOCK_ID' => CATALOG_SKU_IBLOCK_ID,
				'ACTIVE' => 'Y',
				'=PROPERTY_TYPE' => [
					\Bitrix\Iblock\PropertyTable::TYPE_LIST,
					\Bitrix\Iblock\PropertyTable::TYPE_STRING,
					\Bitrix\Iblock\PropertyTable::TYPE_NUMBER,
				]
			),
		));
		$skuProps = [];
		while ($element = $rsProperty->fetch()) {
			$skuProps[$element['CODE']] = $element['ID'];
		}
		$rsProperty = \Bitrix\Iblock\PropertyTable::getList(array(
			'filter' => array(
				'IBLOCK_ID' => CATALOG_IBLOCK_ID,
				'ACTIVE' => 'Y',
				'=PROPERTY_TYPE' => [
					\Bitrix\Iblock\PropertyTable::TYPE_LIST,
					\Bitrix\Iblock\PropertyTable::TYPE_STRING,
					\Bitrix\Iblock\PropertyTable::TYPE_NUMBER,
				]
			),
		));

		while ($element = $rsProperty->fetch()) {

			$ibp = new \CIBlockProperty;
			$enumValues = array();
			$xmlIdCatalog = array();
			if($element['PROPERTY_TYPE'] == "L") {
				$enum_list = \Bitrix\Iblock\PropertyEnumerationTable::getList([
					'filter' => [
						'PROPERTY_ID' => $element['ID'],
					],
					'select' => [
						'*'
					]])->fetchAll();
				foreach ($enum_list as $list) {
					$xmlIdCatalog[] = $list["XML_ID"];
					$enumValues[$list["ID"]] = $list;
				}
			}

			if ($skuProps[$element['CODE']]) {

				$ibp->Update($skuProps[$element['CODE']],[
					"NAME" => $element['NAME'],
				]);

			} else {

				$arFields = Array(
					"NAME" => $element['NAME'],
					"ACTIVE" => "Y",
					"SORT" => "7777",
					"CODE" => $element['CODE'],
					"PROPERTY_TYPE" => $element['PROPERTY_TYPE'],
					"IBLOCK_ID" => CATALOG_SKU_IBLOCK_ID,
				);

				$PropID = $ibp->Add($arFields);
				$skuProps[$element['CODE']] = $PropID;
			}

			if ($enumValues) {
				$smallEnum = array();
				$EnumId = array();
				$property_enums = \CIBlockPropertyEnum::GetList(array("DEF" => "DESC", "SORT" => "ASC"), array("IBLOCK_ID" => CATALOG_SKU_IBLOCK_ID, "CODE" => $element["CODE"]));
				while ($enum_fields = $property_enums->GetNext()) {
					$smallEnum[] = $enum_fields["XML_ID"];
					$EnumId[$enum_fields["XML_ID"]] = $enum_fields["ID"];
				}

				$ibpenum = new \CIBlockPropertyEnum;
				foreach ($enumValues as $val) {
					if (!in_array($val["XML_ID"], $smallEnum)) {
						$updEnum = $ibpenum->Add(array('PROPERTY_ID' => $skuProps[$element["CODE"]], 'VALUE' => $val['VALUE']));
						$ibpenum->Update($updEnum, array('VALUE' => $val['VALUE'], 'XML_ID' => $val['XML_ID']));
					} else {
						$ibpenum->Update($EnumId[$val['VALUE']], array('VALUE' => $val['VALUE'], 'XML_ID' => $val['XML_ID']));
					}
				}
			}
		}

		$elements = $classCatalog::getList([
			'select' => ['*'],
			'filter' => [],
		])->fetchAll();
		foreach ($elements as $element) {
			$dbProperty = \CIBlockElement::getProperty(
				$element['IBLOCK_ID'],
				$element['ID']
			);
			$props = array();
			$arPropTransfer = array();
			$articleList = [];
			while ($arProperty = $dbProperty->Fetch()) {

					if ($arProperty['CODE'] == 'CML2_ARTICLE') {
						if(!in_array($arProperty["VALUE"], $articleList)) {
							$articleList[] = $arProperty["VALUE"];
						}
					}
					if ($arProperty['CODE'] == 'CML2_TRAITS') {
						if (!$props['CML2_TRAITS']) {
							$props[$arProperty['CODE']] = $arProperty;
							$props['CML2_TRAITS']["DESCRIPTION"] = array();
							$props['CML2_TRAITS']["VALUE"] = array();
						}
						$props['CML2_TRAITS']["DESCRIPTION"][] = $arProperty["DESCRIPTION"];
						$props['CML2_TRAITS']["VALUE"][] = $arProperty["VALUE"];
					} else {
						$props[$arProperty['CODE']] = $arProperty;
					}
					if ($arProperty['PROPERTY_TYPE'] == "L") {
						$rsPropertySku = \Bitrix\Iblock\PropertyTable::getList(array(
							'filter' => array('IBLOCK_ID' => CATALOG_SKU_IBLOCK_ID, 'CODE' => $arProperty["CODE"]),
							'select' => array('ID'),
						));
						$arPropertySku = $rsPropertySku->fetch();
						$arPropTransfer[$arProperty['CODE']] = false;
						$enumXmlList = \Bitrix\Iblock\PropertyEnumerationTable::getList(['filter' => ['PROPERTY_ID' => $arPropertySku["ID"], '=VALUE' => $arProperty["VALUE_ENUM"]], 'select' => ['*'], 'order' => ['SORT' => 'ASC']])->fetchAll();
						if($arProperty['MULTIPLE'] == "Y") {
							foreach($enumXmlList as $enumXml) {
								$arPropTransfer[$arProperty['CODE']][] = $enumXml["ID"];
							}
						} else {
							$arPropTransfer[$arProperty['CODE']] = $enumXmlList[0]["ID"];
						}
					} else {
						$arPropTransfer[$arProperty['CODE']] = $arProperty["VALUE"];
					}

			}

			$arPropTransfer["CML2_TRAITS"] = array();
			foreach ($props['CML2_TRAITS']["DESCRIPTION"] as $key => $desc) {
				$arPropTransfer["CML2_TRAITS"][$key]["VALUE"] = $props['CML2_TRAITS']["VALUE"][$key];
				$arPropTransfer["CML2_TRAITS"][$key]["DESCRIPTION"] = $desc;
			}

			$enumArtList = \Bitrix\Iblock\PropertyEnumerationTable::getList(['filter' => ['PROPERTY_ID' => "2055", '=VALUE' => trim($arPropTransfer["CML2_ARTICLE"])], 'select' => ['*'], 'order' => ['SORT' => 'ASC']])->fetchAll();

			$arPropTransfer["ARTICLE_LIST"] = $enumArtList[0]["ID"];

			if(!$arPropTransfer["ARTICLE_LIST"] && $arPropTransfer["CML2_ARTICLE"]) {

				$ibpenum = new \CIBlockPropertyEnum;
				$updArticleEnum = $ibpenum->Add(array('PROPERTY_ID' => '2055', 'VALUE' => $arPropTransfer["CML2_ARTICLE"]));
				$arPropTransfer["ARTICLE_LIST"] = $updArticleEnum;
			}

			$traits = [];
			foreach ($props['CML2_TRAITS']['DESCRIPTION'] as $k => $v) {
				$traits[$v] = $props['CML2_TRAITS']['VALUE'][$k];
			}
			$elementsSku = $classSku::getList([
				'select' => ['ID'],
				'filter' => ['XML_ID' => $element["XML_ID"]],
			])->fetch();
			if (!empty($traits) && $traits['Номенклатура владелец']) {

				// кратность товара
				$multiplicity = 1;
				if ($traits['Минимальная партия'] > 0) {
					$multiplicity = $traits['Минимальная партия'];
				}

				$elementsXmlID = $classCatalog::getList([
					'select' => ['ID'],
					'filter' => ['XML_ID' => $traits['Номенклатура владелец']],
				])->fetchAll();

				\CIBlockElement::SetPropertyValuesEx($element["ID"], CATALOG_IBLOCK_ID, array('MULTIPLICITY' => $multiplicity));
				\CIBlockElement::SetPropertyValuesEx($elementsSku["ID"], CATALOG_SKU_IBLOCK_ID, $arPropTransfer);

				$catProps = [];

				if ($props['VES_1_SHTUKI_KG']){
					$catProps['WEIGHT'] = self::convert($props['VES_1_SHTUKI_KG']['VALUE_ENUM'])/$multiplicity;
				}
				if ($props['SHIRINA_UPAKOVKI_M']){
					$catProps['WIDTH'] = self::convert($props['SHIRINA_UPAKOVKI_M']['VALUE_ENUM']);
				}
				if ($props['VYSOTA_UPAKOVKI_M']){
					$catProps['HEIGHT'] = self::convert($props['VYSOTA_UPAKOVKI_M']['VALUE_ENUM']);
				}
				if ($props['DLINA_UPAKOVKI_M']){
					$catProps['LENGTH'] = self::convert($props['DLINA_UPAKOVKI_M']['VALUE_ENUM']);
				}

				\Bitrix\Catalog\Model\Product::update($elementsSku["ID"], $catProps);
				$mRatioRow = \Bitrix\Catalog\MeasureRatioTable::getRow(['filter' => ['=PRODUCT_ID' => $elementsSku["ID"]]]);
				if ($mRatioRow['ID'] > 0){
					\Bitrix\Catalog\MeasureRatioTable::update($mRatioRow['ID'], ["RATIO" => $multiplicity]);
				}
			} else {

				\CIBlockElement::SetPropertyValuesEx($elementsSku["ID"], CATALOG_SKU_IBLOCK_ID, array("CML2_ARTICLE" => $arPropTransfer["CML2_ARTICLE"]));
				\CIBlockElement::SetPropertyValuesEx($elementsSku["ID"], CATALOG_SKU_IBLOCK_ID, array("ARTICLE_LIST" => $arPropTransfer["ARTICLE_LIST"]));
			}

		}
	}

	public static function propGroups()
	{
		if (!\Bitrix\Main\Loader::IncludeModule("highloadblock")){
			return false;
		}
		$entity = HighloadBlockTable::compileEntity('IzhNaborySvoystvNomenklatury');
		$htPropsClass = $entity->getDataClass();
		$res = $htPropsClass::getList();
		$propGoups = [];
		while($row = $res->fetch()){
			$row['UF_TABLITSASVOYSTV'] = json_decode($row['UF_TABLITSASVOYSTV'],true);
			$props =  [];
			if (is_array($row['UF_TABLITSASVOYSTV'])){
				foreach($row['UF_TABLITSASVOYSTV'] as $prop){
					$props[] = $prop['УИНСвойства'];
				}
			}
			$propGoups[$row['UF_KOD']] = $props;
		}
		return $propGoups;
	}

	public static function convert($value,$unitNum = 1000)
	{
		$value = trim($value);
		$value = str_replace(',','.',$value);
		$value = $value*$unitNum;
		return $value;
	}
}