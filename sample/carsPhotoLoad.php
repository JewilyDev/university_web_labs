<?php

/*
Задача: Есть интернет-магазин автостёкол. Стёкол очень много, автомобилей, которые эти стёкла используют, тоже много.
Есть прайс на сайте(Кривущий как не знаю что!), и есть абсолютно несвязанная "БД" с картинками по маркам автомобилей.
В кавычках, потому что это архив с фотками + json в три километра.
Этот несомненно монструозный и некрасивый код из тысячи циклов сопоставляет название/спец.поле из товара на сайте с данными из файла, и дергает картиночку из архива(уже распакованного), устанавливая оную на сайт.
К сожалению, пока решение плохо: если не получается сопоставить по нейму, нужно руками в адмике товару в спец.поле писать название как в json, но заказ принят и всех всё устроило.
Если попросят - допишу чтобы было как у людей, а пока только как у меня :)
В целом, это было отличное упражнение на сбор хеш-табличек нужного вида. Как прекрасно, что в пхп это настолько легко реализуется.

*/

class carsPhotoLoad {
    public static function photoCars() {
        CModule::IncludeModule("iblock");
        CModule::IncludeModule("file");

        $IBLOCK_ID = 34;
        $PROP_CODE = 'GLASS_TYPE';
        $pathPictureGlass = $_SERVER["DOCUMENT_ROOT"]."/upload/cars/photos/";

        $arrayPhotos = drawArray(new DirectoryIterator($_SERVER["DOCUMENT_ROOT"].'/upload/cars/photos/'));

        $srcArray = array();

        $arFilter = [
            'IBLOCK_ID' => $IBLOCK_ID,
        ];

        $arSelect = [
            'ID',
            'NAME',
            'CODE',
            'DETAIL_PICTURE',
            'IBLOCK_SECTION_ID',
            'PROPERTY_'.$PROP_CODE,
        ];


        /* SECTION IMAGES */

        $my_sections = CIBlockSection::GetList (
            Array("ID" => "ASC"),
            Array("IBLOCK_ID" => $IBLOCK_ID, "ACTIVE" => "Y"),
            false,
            Array('ID', 'NAME', 'CODE', 'PICTURE', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID', 'UF_*')
        );
        $srcSectionPath = $pathPictureGlass."model-glass.jpg";

        $generationCar = '[generation_id]';

        $arrayResponseCars = array();

        $response = file_get_contents($_SERVER["DOCUMENT_ROOT"].'/upload/cars/base.json');
        $response = json_decode($response, true);
        $arMarks = array();
        $arMarksModel = array();
        $index = 0;
        while($ar_fieldsSection = $my_sections->GetNext()) {

            $ar_fieldsSection['UF_MARKS_NAME'] = strtoupper($ar_fieldsSection['UF_MARKS_NAME']);
            $ar_fieldsSection['NAME'] = strtoupper($ar_fieldsSection['NAME']);

            if($ar_fieldsSection["DEPTH_LEVEL"] == 1) {

                if($ar_fieldsSection['UF_MARKS_NAME']){
                    $arMarks[] = $ar_fieldsSection['UF_MARKS_NAME'];
                    $arMarksModel[$ar_fieldsSection["ID"]] = array('NAME' => $ar_fieldsSection['UF_MARKS_NAME']);
                }
                else{
                    $arMarks[] = $ar_fieldsSection["NAME"];
                    $arMarksModel[$ar_fieldsSection["ID"]] = array('NAME' => $ar_fieldsSection["NAME"]);
                }

            }
            if($ar_fieldsSection["DEPTH_LEVEL"] == 2) {
                if($ar_fieldsSection['UF_MARKS_NAME']){
                    $arMarksModel[$ar_fieldsSection["IBLOCK_SECTION_ID"]]['MODELS'][$ar_fieldsSection['UF_MARKS_NAME']] = $ar_fieldsSection['UF_MARKS_NAME'];
                }
                else{
                    $arMarksModel[$ar_fieldsSection["IBLOCK_SECTION_ID"]]['MODELS'][$ar_fieldsSection['NAME']] = $ar_fieldsSection['NAME'];

                }

            }
            $srcArray["CAR_MODEL_PICTURE"][] = $pathPictureGlass.$ar_fieldsSection["NAME"].".jpg";

            if ($srcSectionPath) {
                $srcSection= $srcSectionPath;
                $arFileSection = CFile::MakeFileArray($srcSection);
                $arPICTURESection = $arFileSection;
                $arPICTURESection["MODULE_ID"] = "iblock";

            }
        }
        $arMarksFinal = array();

        foreach ($arMarksModel as $markModel){
            $arMarksFinal[$markModel['NAME']] = $markModel['MODELS'];
        }
        
        $arConnectionsID  = array();
        $arConnectionsName  = array();
        //Массив из русских и латинских имён марок автомобилей из базы данных
        $arRespName = array();
        $arMarkNot = array();
        foreach ($response as $key => $mark){

            $arMarkNot[$mark['name']] = array_column($mark['models'],'name','name');

            $mark['name'] = strtoupper($mark['name']);
            $mark['id'] = strtoupper($mark['id']);
            $arRespName[$key][] = $mark['name'];
            $arRespName[$key][] = $mark['cyrillic-name'];
            $posID = array_search($mark['id'],$arMarks);
            $posName = array_search($mark['name'],$arMarks);
            $modelNum = 0;

            if($posID !== false){
                $arConnectionsID[$mark['id']] = array_column($mark['models'],'name','name');
                foreach ($arConnectionsID[$mark['id']] as $j => $model){
                    $arConnectionsID[$mark['id']][strtoupper($j)] = $arConnectionsID[$mark['id']][$j];
                    unset($arConnectionsID[$mark['id']][$j]);
                    $arConnectionsID[$mark['id']][strtoupper($j)] = $mark['models'][$modelNum]['generations'][0]['id']['configurations'][0]['id'];
                    $modelNum++;
                }
            }

            $modelNum = 0;
            if($posName !== false){

                $arConnectionsName[$mark['name']] = array_column($mark['models'],'name','name');
                foreach ($arConnectionsName[$mark['name']] as $j => $model){
                    $arConnectionsName[$mark['name']][strtoupper($j)] = $arConnectionsName[$mark['id']][$j];
                    unset($arConnectionsName[$mark['name']][$j]);
                    $arConnectionsName[$mark['name']][strtoupper($j)] = $mark['models'][$modelNum]['generations'][0]['configurations'][0]['id'];
                    $modelNum++;
                }
            }
        }
        $arConnections = array_merge($arConnectionsID,$arConnectionsName);

        $arLeft = array();
        foreach ($arMarksFinal as $key => $markFinal) {

            foreach ($markFinal as $j => $model){
                if($arConnections[$key][$model]){
                    $arfilter = array(
                        "IBLOCK_ID" => $IBLOCK_ID,
                        "ACTIVE" => "Y",
                        "NAME" => $model
                    );
                    $model_obj = CIBlockSection::GetList (
                        Array("ID" => "ASC"),
                        $arfilter,
                        false,
                        Array('ID','PICTURE', 'UF_*')
                    );

                    if($ar_model = $model_obj->GetNext()) {

                        $arFields = array(
                            "PICTURE" => CFile::MakeFileArray($pathPictureGlass.$arConnections[$key][$model].".jpg"),
                        );
                        $model_sec = new CIBlockSection;
                        $model_sec->Update($ar_model['ID'], $arFields, false, false, true);

                    }

                    $arfilter = array(
                        "IBLOCK_ID" => $IBLOCK_ID,
                        "ACTIVE" => "Y",
                        "UF_MARKS_NAME" => $model
                    );
                    $model_obj = CIBlockSection::GetList (
                        Array("ID" => "ASC"),
                        $arfilter,
                        false,
                        Array('ID','PICTURE')
                    );
                    while($ar_model = $model_obj->GetNext()) {
                        $arFields = array(
                            "PICTURE" => CFile::MakeFileArray($pathPictureGlass.$arConnections[$key][$model].".jpg"),
                        );
                        $model_sec = new CIBlockSection;
                        $model_sec->Update($ar_model['ID'], $arFields, false, false, true);
                    }
                } else {
                    $arLeft[] = $model;
                }
            }

        }
    }
}