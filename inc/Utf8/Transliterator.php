<?php

namespace dokuwiki\Utf8;

/**
 * DokuWiki Transliterator for cleanID
 *
 * Transliterator provides transliteration of strings, in a loose sense to mean
 * transformation of a word from a source language into a sequence of similar sounds
 * in the target language. For example, "Αλφaβητικός" is coverted to "Alphabetikos".
 *
 * The Transliterator::transliterate() will help to generate "clean" IDs of page or
 * section headings from UTF-8 text.
 * The transformation is configurable through a compound transform identifier which
 * should be defined in transforms.local.conf file in DOKU_CONF directory.
 *
 * @see https://unicode-org.github.io/icu/userguide/transforms/
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */
class Transliterator
{
    /** @var bool should the intl extension be used if available? For testing only */
    protected static $useIntl = true;

    /** @var Transliterator $transliterator */
    protected static $transliterator;

    protected static $transforms;

    /**
     * Return a sequence of transforms in transliteration
     * The sequence of transforms should be defined in transforms.local.conf file.
     *
     * @return array
     */
    public static function getTransforms()
    {
        // check if intl extension is available
        if (!static::$useIntl || !class_exists('\Transliterator', $autoload = false)) {
            return static::$transforms = [];
        }

        // when transforms not set, load config and set the property
        return static::$transforms ?? static::loadTransforms();
    }

    /**
     * Enable or disable the use of the "intl" extension transliterator.
     * This is used for testing and should not be used in normal code.
     *
     * @param bool $use
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    public static function useIntl($use = true)
    {
        static::$useIntl = $use;
    }

    /**
     * transliterate text
     *
     * @param string $text
     * @param string|array $ids  (optional)
     * @return string
     */
    public static function transliterate($text, $ids = null)
    {
        if ($ids === null) {
            $transliterator = static::$transliterator ?? static::create();
            return $transliterator->transliterate($text);
        } else {
            $transforms = static::verifyTransforms((array)$ids);
            // build compound IDs of transliterator
            $ids = (count($transforms) > 0) ? implode(';', $transforms) : 'Any-Null';
            return \Transliterator::create($ids)->transliterate($text);
        }
    }

    /**
     * create configured transliterator
     *
     * @return Transliterator
     */
    protected static function create()
    {
        global $conf;

        // when "intl" extension is not available in your system, instanciate an anonymous class
        if (!static::$useIntl || !class_exists('\Transliterator', $autoload = false)) {
            dbglog("Utf8\Transliterator::create(): intl extension is not available!");
            return static::$transliterator = new class () // anonymous class
            {
                public function transliterate($text)
                {
                  //return $text;
                    return Utf8\PhpString::strtolower($text); // or fallback to call romanize()?
                }
            }; // end of anonymous class
        }

        // load transform entries
        $transforms = static::loadTransforms();

        // build compound IDs of transliterator
        $ids = (count($transforms) > 0) ? implode(';', $transforms) : 'Any-Null';

        // create transliterator
        return static::$transliterator = \Transliterator::create($ids);
    }

    /**
     * load transform entries from the config file
     *
     * @param bool $verify  verify config entries
     * @return array
     */
    public static function loadTransforms($verify = true)
    {
        global $conf;

        // load config from the file that is found first
        $configs = array(
            'cache'   => $conf['cachedir'].'/transforms.conf',
            'local'   => DOKU_CONF.'transforms.local.conf',
            'default' => __DIR__.'/transforms.conf',
        );
        if (!$verify) unset($configs['cache']);

        $transforms = [];
        foreach ($configs as $k => $config) {
            if (!file_exists($config)) continue;
            $transforms = file($config, FILE_IGNORE_NEW_LINES);
            if ($k === 'cache') {
                // check dependency of the cache config
                $cachedtime = filemtime($config);
                if ((@filemtime(__FILE__) < $cachedtime)
                    && (@filemtime($configs['local']) < $cachedtime)
                    && (@filemtime($configs['default']) < $cachedtime)
                    && (@filemtime(DOKU_CONF.'local.php') < $cachedtime)
                ) {
                    return static::$transforms = $transforms;
                } else {
                    unlink($config);
                    continue;
                }
            }
            $transforms = array_map('trim', $transforms);
            $transforms = preg_replace('/\s*#.*$/', '', $transforms); // remove comments
            $transforms = array_filter($transforms);
            break; // exit foreach loop
        }

        if (!$verify) return $transforms;

        // store the verified transforms config into the cache
        $transforms = static::verifyTransforms($transforms);
        if (isset($configs['cache'])) {
            io_saveFile($configs['cache'], implode("\n", $transforms));
            error_log('$$$ created '. $configs['cache']);
        }

        return static::$transforms = $transforms;
    }

    /**
     * eliminate invalid transform entry
     *
     * @param array $transforms
     * @return array
     */
    public static function verifyTransforms(array $transforms, $verbose = false)
    {
        $chkfunc = function ($id) {
            return method_exists(\Transliterator::create($id), 'transliterate');
        };
        foreach ($transforms as $k => $transform) {
            if (!$chkfunc($transform)) {
                if ($verbose) msg('invalid entry line '.($k +1).': '.$transform, -1);
                unset($transforms[$k]);
            }
        }
        return $transforms;
    }

    /**
     * check validity of a transform id entry
     *
     * @param string $transform
     * @return bool
     */
    protected static function checkTransform($transform)
    {
        $translit = \Transliterator::create($transform);
        return method_exists($translit, 'transliterate');
    }


    /**
     * transform only to Latin
     *
     * @param string $text
     * @return string
     */
    public static function toLatin($text)
    {
        $transliterator = static::$transliterator ?? static::create();
        $transforms = array_diff(static::$transforms, ['de-ASCII','Latin-ASCII','tr-Lower','Any-Lower']);
        $ids = (count($transforms) > 0) ? implode('; ', $transforms) : 'Any-Null';
        return \Transliterator::create($ids)->transliterate($text);
    }

    /**
     * transform only to Lower
     *
     * @param string $text
     * @return string
     */
    public static function toLower($text)
    {
        $transliterator = static::$transliterator ?? static::create();
        $transforms = array_intersect(['tr-Lower','Any-Lower'], static::$transforms);
        $ids = (count($transforms) > 0) ? implode('; ', $transforms) : 'Any-Null';
        return \Transliterator::create($ids)->transliterate($text);
    }

    /**
     * transform only to ASCII
     *
     * @param string $text
     * @return string
     */
    public static function toASCII($text)
    {
        $transliterator = static::$transliterator ?? static::create();
        $transforms = array_intersect(['de-ASCII','Latin-ASCII'], static::$transforms);
        $ids = (count($transforms) > 0) ? implode('; ', $transforms) : 'Any-Null';
        return \Transliterator::create($ids)->transliterate($text);
    }

    /**
     * transform into lowercase
     *
     * @param string $text
     * @return string
     */
    public static function lower($text)
    {
        return \Transliterator::create('lower')->transliterate($text);
    }

    /**
     * transform into uppercase
     *
     * @param string $text
     * @return string
     */
    public static function upper($text)
    {
        return \Transliterator::create('upper')->transliterate($text);
    }

    /**
     * deaccent using ICU transliterator
     *
     * @param string $text
     * @return string
     */
    public static function deaccent($text)
    {
        // remove accents from characters
        $ids = 'NFD; [:Nonspacing Mark:] Remove; NFC';
        return \Transliterator::create($ids)->transliterate($text);
    }

}
