<?php
namespace App\Filament;
trait TraduzirNavegacao {
 public static function getNavigationLabel(): string {return __(parent::getNavigationLabel());}
 public static function getNavigationGroup(): string|\UnitEnum|null {$v=parent::getNavigationGroup();return is_string($v)?__($v):$v;}
 public static function getNavigationParentItem(): ?string {$v=parent::getNavigationParentItem();return is_string($v)?__($v):$v;}
 public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable {$v=parent::getTitle();return is_string($v)?__($v):$v;}
}
