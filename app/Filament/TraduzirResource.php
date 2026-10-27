<?php
namespace App\Filament;
trait TraduzirResource {
 use TraduzirNavegacao;
 public static function getModelLabel(): string {return __(parent::getModelLabel());}
 public static function getPluralModelLabel(): string {return __(parent::getPluralModelLabel());}
}
