<?php


/* @var $this yii\web\View */
/* @var $name string */
/* @var $message string */
/* @var $exception Exception */
use app\assets\LoginAsset;
use yii\helpers\Html;

LoginAsset::register($this);
$this->title = 'Registered';
?>
<div class="site-login">

    <h1><?= Html::encode($this->title) ?></h1>

    <div class="body-content">
        Thank you for your registration <?= Html::encode($username) ?>
    </div>

</div>
