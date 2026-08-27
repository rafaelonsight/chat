<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class TextoComLinks
{
    public static function render(string $texto): HtmlString
    {
        $partes = preg_split('~((?:https?://|www\.)[^\s<>"\x27]+)~iu', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html = '';
        foreach ($partes as $i => $parte) {
            if ($i % 2 === 0) {
                $html .= e($parte);
                continue;
            }
            $url = rtrim($parte, '.,;:!?)');
            $fim = substr($parte, strlen($url));
            $destino = str_starts_with(strtolower($url), 'www.') ? 'https://'.$url : $url;
            $html .= '<a href="'.e($destino).'" target="_blank" rel="noopener noreferrer nofollow" class="underline break-all">'.e($url).'</a>'.e($fim);
        }
        return new HtmlString($html);
    }
}
