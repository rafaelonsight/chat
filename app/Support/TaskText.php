<?php
namespace App\Support;
use Illuminate\Support\Str;
class TaskText {
 public static function render(?string $text):string {
  return strip_tags(Str::markdown($text??'',[
   'html_input'=>'strip','allow_unsafe_links'=>false,'max_nesting_level'=>15,
   'renderer'=>['soft_break'=>'<br>'],
  ]),'<p><br><a><strong><em><del><ul><ol><li><blockquote><code><pre><hr><h1><h2><h3><h4>');
 }
}
