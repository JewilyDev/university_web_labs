<?php
require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");

/*
В этом и файле с индексом (2) решалась по-сути одна и та же большая задача реализации Торговых Предложений на сайте, где их "Из коробки" не было.
Для того, чтобы они работали, потребовалось написать немало костылей, и это - один из них. Для определения того, какое из ТП было выбрано пользователем,
на этот файл отправлялся запрос содержащий в себе : Тип запроса(установить или обновить), айди товара, и сами значения "ratios"(Сайт был про овощи, там были всякие 2кг 3кг и т.п)
Внутри этот запрос обрабаытывается и записывает в свойства корзины этому товару фиктивные поля, которых в общем-то в БД не существует, и таким  образом эта вся бутафория 
появляется в корзине и странице оформления заказа, а затем уезжает клиенту и менеджеру на почту.(Это уже происходит во многом стандартным способом, предоставляемым фреймворком)
*/

if (CModule::IncludeModule("catalog") && CModule::IncludeModule("sale")) {

    $success = false;

    if($_REQUEST['type'] == 'set') {

        $ratiosData = json_decode($_REQUEST['ratios']);

        foreach ($ratiosData as $key => $data) {
            $ratiosData[$key] = (array)json_decode($data);
        }

        $id = intval($_POST['id']);


        $basket = \Bitrix\Sale\Basket::loadItemsForFUser(
            \Bitrix\Sale\Fuser::getId(),
            \Bitrix\Main\Context::getCurrent()->getSite()
        );

        // массив объектов \Bitrix\Sale\BasketItem
        $basketItems = $basket->getBasketItems();


        $arCollectionItems = array();
        foreach ($basketItems as $basketItem) {

            if ($basketItem->getProductId() == $id) {
                // Свойства записи, массив объектов Sale\BasketPropertyItem
                $collection = $basketItem->getPropertyCollection();

                $success = true;

                foreach ($ratiosData as $key => $data){

                    if($data["total"] != 0){
                        $arCollectionItems[$key] = $collection->createItem();
                        $arCollectionItems[$key]->setFields([
                            'NAME' => $data["name"],
                            'CODE' => $data["code"],
                            'VALUE' => floatval($data["total"]),
                        ]);
                        $collection->save();
                    }
                }
            }
        }
    }

    if($_REQUEST['type'] == 'up') {

        $ratiosData = json_decode($_REQUEST['ratios']);

        foreach ($ratiosData as $key => $data) {
            $ratiosData[$key] = (array)json_decode($data);
        }

        $id = intval($_POST['id']);


        $basket = \Bitrix\Sale\Basket::loadItemsForFUser(
            \Bitrix\Sale\Fuser::getId(),
            \Bitrix\Main\Context::getCurrent()->getSite()
        );

        // массив объектов \Bitrix\Sale\BasketItem
        $basketItems = $basket->getBasketItems();

        foreach ($basketItems as $basketItem) {
            if ($basketItem->getProductId() == $id) {

                // Свойства записи, массив объектов Sale\BasketPropertyItem
                $collection = $basketItem->getPropertyCollection();

                $success = true;

                foreach ($ratiosData as $key => $data){

                    $propertyExists = false;

                    foreach($collection as $property){

                        if($data["code"] === $property->getField('CODE')) {
                                    $finalData = floatval($data["total"]);
                                    $property = $collection->getItemById($property->getField('ID'));
                                    $property->setField('VALUE', $finalData);
                                    $collection->save();
                            $propertyExists = true;

                        }

                    }

                    if(!$propertyExists) {

                        if($data["total"] !== 0){

                            $itemCollect = $collection->createItem();
                            $itemCollect->setFields([
                                'NAME' => $data["name"],
                                'CODE' => $data["code"],
                                'VALUE' => floatval($data["total"]),
                            ]);
                            $collection->save();

                        }
                    }

                }

            }
        }

    }

    if($_REQUEST['type'] == 'get'){

        $id = intval($_POST['id']);
        $basket = \Bitrix\Sale\Basket::loadItemsForFUser(
            \Bitrix\Sale\Fuser::getId(),
            \Bitrix\Main\Context::getCurrent()->getSite()
        );

        // массив объектов \Bitrix\Sale\BasketItem
        $basketItems = $basket->getBasketItems();

        $arCollectionItems = array();
        foreach ($basketItems as $basketItem) {
            if ($basketItem->getProductId() == $id) {
                $collection = $basketItem->getPropertyCollection();
                $success = true;
                $index = 0;
                foreach($collection as $property){
                    if($property->getField('CODE') === "RATIO_VALUES_0"
                        || $property->getField('CODE') === "RATIO_VALUES_1"
                        || $property->getField('CODE') === "RATIO_VALUES_2"
                        || $property->getField('CODE') === "RATIO_VALUES_3"
                        || $property->getField('CODE') === "RATIO_VALUES_4"
                        || $property->getField('CODE') === "RATIO_VALUES_5"
                        || $property->getField('CODE') === "RATIO_VALUES_6") {

                        $ratiosData[] = array(
                            'name' => $property->getField('NAME'),
                            'total' => floatval($property->getField('VALUE')),
                            'code' => $property->getField('CODE')
                        );

                    }
                }
            }
        }

    }

}

if(!$success) {
    echo null;
} else {
    echo json_encode($ratiosData ? $ratiosData : null);
}