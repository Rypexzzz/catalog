<?php
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/header.php');
$APPLICATION->SetTitle("Каталог услуг ЦК ПР");
use Bitrix\Main\Page\Asset;
Asset::getInstance()->addJs("/catalog/_assets/nav.js");
?>

<style>
/* — базовые сбросы — */
body{margin:0;}
.project-calc-page,
.project-calc-page *{box-sizing:border-box;}
.project-calc-page{background:#fff;overflow-x:hidden;}

/* — лэйаут — */
.project-calc-page .container{
    width:100%;
    padding:40px 3%;
    display:flex;
    align-items:center;
    gap:5%;
    min-height: 70vh;
}
.project-calc-page .left-column{
    flex:0 1 40%;
    display:flex;
    flex-direction:column;
    justify-content:center;
}
.project-calc-page .right-column{
    flex:0 1 55%;
}

/* — контент — */
.project-calc-page .title{
    font:700 clamp(24px, 2.5vw, 36px) 'HeliosCond',Arial,sans-serif;
    margin:0 0 30px;
    color:#000;
    line-height:1.3;
}

.project-calc-page .buttons-container{display:flex;gap:20px;}

.project-calc-page .btn{
    flex:1;
    padding:clamp(10px, 1.5vh, 15px) clamp(15px, 2vw, 25px);
    background:#0078C0;
    color:#fff;
    border:none;border-radius:4px;
    font:400 clamp(14px, 1.2vw, 18px) 'HeliosCond',Arial,sans-serif;
    text-align:center;text-decoration:none;white-space:nowrap;
    cursor:pointer;
    transition:background .2s,color .2s;
}
.project-calc-page .btn:hover,
.project-calc-page .btn:focus{
    background:#D7F0FF;
    color:#003b7f;
}

.project-calc-page .svg-container{
    width:100%;
    display:flex;
    justify-content:center;
    align-items:center;
}

.project-calc-page .svg-container img{
    width:100%;
    height:auto;
    max-height:500px;
    object-fit:contain;
    display:block;
}

/* — адаптив — */
@media(max-width:992px){
  .project-calc-page .container{
      flex-direction:column;
      gap:50px;
      text-align:center;
      padding:40px 5%;
  }
  .project-calc-page .left-column,
  .project-calc-page .right-column{
      flex:0 1 auto;
      width:100%;
  }
  .project-calc-page .buttons-container{
      justify-content:center;
  }
  .project-calc-page .svg-container img{
      max-height:300px;
  }
}

@media(max-width:768px){
  .project-calc-page .container{
      gap:40px;
      padding:30px 5%;
  }
  .project-calc-page .buttons-container{
      flex-direction:column;
      gap:15px;
  }
  .project-calc-page .btn{
      width:100%;
  }
  .project-calc-page .svg-container img{
      max-height:250px;
  }
}
</style>

<div class="project-calc-page">
  <div class="container">
    <div class="left-column">
      <h1 class="title">Инструмент для расчета стоимости проектов</h1>

      <div class="buttons-container">
        <a href="list/" class="btn">Перейти&nbsp;к каталогу</a>
      </div>
    </div>

    <div class="right-column">
      <div class="svg-container">
        <img src="/local/include/image_catalog.svg" alt="Иллюстрация к калькулятору проектов">
      </div>
    </div>
  </div>
</div>

<?php
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
?>