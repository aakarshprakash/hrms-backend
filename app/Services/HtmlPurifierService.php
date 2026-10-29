<?php

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

class HtmlPurifierService
{
    protected HTMLPurifier $purifier;

    public function __construct()
    {
        // The definition cache lives in storage, which a fresh checkout or deploy doesn't create.
        $cache = storage_path('app/htmlpurifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cache);
        $config->set('HTML.Allowed', 'p,br,strong,em,u,b,i,ul,ol,li,h1,h2,h3,h4,h5,h6,span[style],div[style],table,thead,tbody,tr,th,td,img[src|alt|width|height|style],a[href]');
        $config->set('CSS.AllowedProperties', 'font,font-size,font-weight,font-style,font-family,text-decoration,text-align,color,background-color,border,border-collapse,padding,margin,width,height,line-height');
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', false);

        $this->purifier = new HTMLPurifier($config);
    }

    public function purify(string $html): string
    {
        return $this->purifier->purify($html);
    }
}
