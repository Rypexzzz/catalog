<?php
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
$APPLICATION->SetTitle("Каталог услуг");
use Bitrix\Main\Page\Asset;
Asset::getInstance()->addJs("/catalog/_assets/nav.js");
$APPLICATION->AddHeadString(
  '<style>
     .workarea-content{background:none;}
   </style>',
  true
);
$APPLICATION->IncludeComponent(
  'catalog_components:service.catalog',
  '',
  [],
  false
);
?>

<?php
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
