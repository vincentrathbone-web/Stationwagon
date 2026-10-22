<?php
declare(strict_types=1);

namespace Stationwagon;

/**
 * The looks (skins) a viewer can pick. A skin is one block of tokens in assets/app.css
 * (`:root[data-skin="id"]`, the first one is plain `:root`); the components read only tokens.
 * The choice is the viewer's, kept in their browser (assets/skin.js) so every screen on that device
 * follows it; the server never stores it. To add a skin: a token block in app.css and a line here.
 */
final class Skins
{
    public const DEFAULT = 'green';
    public const STORE_KEY = 'sw.skin';

    /** id => the name the picker shows */
    public const ALL = ['green' => 'Green', 'hifi' => 'Hi-fi'];

    /**
     * Inline script for <head>: sets data-skin before the page draws, so a page never flashes the
     * default look first. Reads only; skin.js does the picking and the saving.
     */
    public static function headScript(): string
    {
        $ids = json_encode(array_keys(self::ALL));
        $key = json_encode(self::STORE_KEY);
        return "<script>(function(){try{var s=localStorage.getItem($key);if(s&&$ids.indexOf(s)>0)document.documentElement.setAttribute('data-skin',s);}catch(e){}})();</script>\n";
    }

    /** The picker: a select on the full pages, a small button that steps through the skins in the embed. */
    public static function picker(bool $compact = false): string
    {
        $data = h(json_encode(self::ALL));
        if ($compact) {
            return '<button class="skinbtn" type="button" data-skin-pick data-skins="' . $data . '" title="Change the look on this device">Look</button>';
        }
        $opts = '';
        foreach (self::ALL as $id => $name) $opts .= '<option value="' . h($id) . '">' . h($name) . '</option>';
        return '<label class="skinpick" title="The look on this device, for every screen"><span>Look</span><select data-skin-pick data-skins="' . $data . '">' . $opts . '</select></label>';
    }
}
