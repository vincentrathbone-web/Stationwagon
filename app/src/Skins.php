<?php
declare(strict_types=1);

namespace Stationwagon;

use Throwable;

/**
 * The looks (skins) a viewer can pick. A skin is one block of tokens in assets/app.css
 * (`:root[data-skin="id"]`, the first one is plain `:root`); the components read only tokens.
 * The choice is the viewer's, kept in their browser (assets/skin.js) so every screen on that device
 * follows it; the server never stores it. A device that hasn't picked one gets the station's own look
 * (round 83: Sound & safety → Look, `look` in the station's settings). To add a skin: a token block in
 * app.css, a line here and a choice in StationSettings::FIELDS['look'].
 */
final class Skins
{
    public const DEFAULT = 'green';
    public const STORE_KEY = 'sw.skin';

    /** id => the name the picker shows */
    public const ALL = ['green' => 'Green', 'hifi' => 'Hi-fi', 'ocean' => 'Ocean'];

    /** The station's own look (round 83): what a device sees until someone picks one there. */
    public static function stationDefault(): string
    {
        static $look = null;
        if ($look !== null) {
            return $look;
        }
        $look = self::DEFAULT;
        try {
            if (Config::isInstalled()) {
                $v = (string) (Planner::settings(Streams::main(Db::fromConfig()))['look'] ?? self::DEFAULT);
                $look = isset(self::ALL[$v]) ? $v : self::DEFAULT;
            }
        } catch (Throwable) {
            // no database yet (setup): the default look
        }
        return $look;
    }

    /**
     * Inline script for <head>: sets data-skin before the page draws, so a page never flashes the
     * default look first: the device's own choice, else the station's look (kept in data-skin-default
     * for skin.js). Reads only; skin.js does the picking and the saving.
     */
    public static function headScript(): string
    {
        $ids = json_encode(array_keys(self::ALL));
        $key = json_encode(self::STORE_KEY);
        $def = json_encode(self::stationDefault());
        return "<script>(function(){var r=document.documentElement,d=$def,s=null;r.setAttribute('data-skin-default',d);"
            . "try{s=localStorage.getItem($key);}catch(e){}if(!s||$ids.indexOf(s)<0)s=d;if($ids.indexOf(s)>0)r.setAttribute('data-skin',s);})();</script>\n";
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
