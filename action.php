<?php
/**
 * ODT Plugin: Exports to ODT
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Aurelien Bompard <aurelien@bompard.org>
 * @author       Florian Lamml <info@florian-lamml.de>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

/**
 * Add the template as a page dependency for the caching system
 */
class action_plugin_odt extends DokuWiki_Action_Plugin {

    /**
     * @var array
     */
    protected $list = array();

    /**
     * Register the events
     *
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'convert', array());
        $controller->register_hook('TEMPLATE_PAGETOOLS_DISPLAY', 'BEFORE', $this, 'addbutton', array());
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_cache_prepare');
    }


    /**
     * Add dependencies to cache
     *
     * @param Doku_Event $event
     */
    public function handle_cache_prepare(Doku_Event $event) {
        global $conf, $ID;

        $cache =& $event->data;
        // only the ODT rendering mode needs caching tweaks
        if($cache->mode != "odt") return;

        $odt_meta = p_get_metadata($ID, 'relation odt');
        $template_name = $odt_meta["template"];
        if(!$template_name) {
            return;
        }
        $template_path = $conf['mediadir'] . '/' . $this->getConf("tpl_dir") . "/" . $template_name;
        if(file_exists($template_path)) {
            $cache->depends['files'][] = $template_path;
        }
    }

    /**
     * Add 'export odt'-button to pagetools
     *
     * @param Doku_Event $event
     */
    public function addbutton(Doku_Event $event) {
        global $ID, $REV;

        if($this->getConf('showexportbutton') && $event->data['view'] == 'main') {
            $params = array('do' => 'export_odt');
            if($REV) {
                $params['rev'] = $REV;
            }

            // insert button at position before last (up to top)
            $event->data['items'] = array_slice($event->data['items'], 0, -1, true) +
                array('export_odt' =>
                        '<li>'
                        . '<a href="' . wl($ID, $params) . '"  class="action export_odt" rel="nofollow" title="' . $this->getLang('export_odt_button') . '">'
                        . '<span>' . $this->getLang('export_odt_button') . '</span>'
                        . '</a>'
                        . '</li>'
                ) +
                array_slice($event->data['items'], -1, 1, true);
        }
    }

    /***********************************************************************************
     *  Book export                                                                    *
     ***********************************************************************************/

    /**
     * Do article(s) to ODT conversion work
     *
     * @param Doku_Event $event
     * @return bool
     */
    public function convert(Doku_Event $event) {
        global $ACT;
        global $ID;

        // single page export: rename to the actual renderer component
        if($ACT == 'export_odt') {
            $ACT = 'export_odt_page';
        }

        // the book export?
        if(($ACT != 'export_odtbook') && ($ACT != 'export_odtns')) return false;

        // check user's rights
        if(auth_quickaclcheck($ID) < AUTH_READ) return false;

        if($data = $this->collectExportPages($event)) {
            list($title, $this->list) = $data;
        } else {
            return false;
        }

        // it's ours, no one else's
        $event->preventDefault();

        // prepare cache and its dependencies
        $depends = array();
        $cache = $this->prepareCache($title, $depends);

        // hard work only when no cache available
        if(!$this->getConf('usecache') || !$cache->useCache($depends)) {
            $this->generateODT($cache->cache, $title);
        }

        // deliver the file
        $this->sendODTFile($cache->cache, $title);
        return true;
    }


    /**
     * Obtain list of pages and title, based on url parameters
     *
     * @param Doku_Event $event
     * @return string|bool
     */
    protected function collectExportPages(Doku_Event $event) {
        global $ACT;
        global $ID;
        global $INPUT;
        global $conf;

        // list of one or multiple pages
        $list = array();

        if($ACT == 'export_odt') {
            $list[0] = $ID;
            $title = $INPUT->str('book_title');
            if(!$title) {
                $title = p_get_first_heading($ID);
            }

        } elseif($ACT == 'export_odtns') {
            //check input for title and ns
            if(!$title = $INPUT->str('book_title')) {
                $this->showPageWithErrorMsg($event, 'needtitle');
                return false;
            }
            $docnamespace = cleanID($INPUT->str('book_ns'));
            if(!@is_dir(dirname(wikiFN($docnamespace . ':dummy')))) {
                $this->showPageWithErrorMsg($event, 'needns');
                return false;
            }

            //sort order
            $order = $INPUT->str('book_order', 'natural', true);
            $sortoptions = array('pagename', 'date', 'natural');
            if(!in_array($order, $sortoptions)) {
                $order = 'natural';
            }

            //search depth
            $depth = $INPUT->int('book_nsdepth', 0);
            if($depth < 0) {
                $depth = 0;
            }

            //page search
            $result = array();
            $opts = array('depth' => $depth); //recursive all levels
            $dir = utf8_encodeFN(str_replace(':', '/', $docnamespace));
            search($result, $conf['datadir'], 'search_allpages', $opts, $dir);

            //sorting
            if(count($result) > 0) {
                if($order == 'date') {
                    usort($result, array($this, '_datesort'));
                } elseif($order == 'pagename') {
                    usort($result, array($this, '_pagenamesort'));
                }
            }

            foreach($result as $item) {
                $list[] = $item['id'];
            }

        } elseif(isset($_COOKIE['list-pagelist']) && !empty($_COOKIE['list-pagelist'])) {
            // Here is $ACT == 'export_odtbook'

            //is in Bookmanager of bookcreator plugin a title given?
            if(!$title = $INPUT->str('book_title')) {
                $this->showPageWithErrorMsg($event, 'needtitle');
                return false;
            } else {
                $list = explode("|", $_COOKIE['list-pagelist']);
            }

        } else {
            //show empty bookcreator message
            $this->showPageWithErrorMsg($event, 'empty');
            return false;
        }

        $list = array_map('cleanID', $list);
        return array($title, $list);
    }


    /**
     * Set error notification and reload page again
     *
     * @param Doku_Event $event
     * @param string     $msglangkey key of translation key
     */
    private function showPageWithErrorMsg(Doku_Event $event, $msglangkey) {
        msg($this->getLang($msglangkey), -1);

        $event->data = 'show';
        $_SERVER['REQUEST_METHOD'] = 'POST'; //clears url
    }

    /**
     * Prepare cache
     *
     * @param string $title
     * @param array  $depends (reference) array with dependencies
     * @return cache
     */
    protected function prepareCache($title, &$depends) {
        global $REV;
        global $INPUT;

        //different caches for varying config settings
        $template = $this->getConf("tpl_default");
        $template = $INPUT->get->str('odt-template', $template, true);


        $cachekey = join(',', $this->list)
                    . $REV
                    . $template
                    . $title;
        $cache = new cache($cachekey, '.odt');

        $dependencies = array();
        foreach($this->list as $pageid) {
            $relations = p_get_metadata($pageid, 'relation');

            if(is_array($relations)) {
                if(array_key_exists('media', $relations) && is_array($relations['media'])) {
                    foreach($relations['media'] as $mediaid => $exists) {
                        if($exists) {
                            $dependencies[] = mediaFN($mediaid);
                        }
                    }
                }

                if(array_key_exists('haspart', $relations) && is_array($relations['haspart'])) {
                    foreach($relations['haspart'] as $part_pageid => $exists) {
                        if($exists) {
                            $dependencies[] = wikiFN($part_pageid);
                        }
                    }
                }
            }

            $dependencies[] = metaFN($pageid, '.meta');
        }

        $depends['files'] = array_map('wikiFN', $this->list);
        $depends['files'][] = __FILE__;
        $depends['files'][] = dirname(__FILE__) . '/renderer/page.php';
        $depends['files'][] = dirname(__FILE__) . '/renderer/book.php';
        $depends['files'][] = dirname(__FILE__) . '/plugin.info.txt';
        $depends['files'] = array_merge(
            $depends['files'],
            $dependencies,
            getConfigFiles('main')
        );
        return $cache;
    }

    /**
     * Build a ODT from the articles
     *
     * @param string $cachefile
     * @param string $title
     */
    protected function generateODT($cachefile, $title) {
        global $ID;
        global $REV;

        /** @var renderer_plugin_odt_book $odt */
        $odt = plugin_load('renderer','odt_book');

        // store original pageid
        $keep = $ID;

        // loop over all pages
        $xmlcontent = '';

        $cnt = count($this->list);
        for($n = 0; $n < $cnt; $n++) {
            $page = $this->list[$n];

            // set global pageid to the rendered page
            $ID = $page;
            $xmlcontent .= p_render('odt_book', p_cached_instructions(wikiFN($page, $REV),false,$page), $info);
        }

        //restore ID
        $ID = $keep;

        $odt->doc = $xmlcontent;
        $odt->setTitle($title);
        $odt->finalize_ODTfile();

        // write to cache file
        io_savefile($cachefile, $odt->doc);
    }

    /**
     * @param string $cachefile
     * @param string $title
     */
    protected function sendODTFile($cachefile, $title) {
        header('Content-Type: application/vnd.oasis.opendocument.text');
        header('Cache-Control: must-revalidate, no-transform, post-check=0, pre-check=0');
        header('Pragma: public');
        http_conditionalRequest(filemtime($cachefile));

        $filename = rawurlencode(cleanID(strtr($title, ':/;"', '    ')));
        if($this->getConf('output') == 'file') {
            header('Content-Disposition: attachment; filename="' . $filename . '.odt";');
        } else {
            header('Content-Disposition: inline; filename="' . $filename . '.odt";');
        }

        //try to send file, and exit if done
        http_sendfile($cachefile);

        $fp = @fopen($cachefile, "rb");
        if($fp) {
            http_rangeRequest($fp, filesize($cachefile), 'application/vnd.oasis.opendocument.text');
        } else {
            header("HTTP/1.0 500 Internal Server Error");
            print "Could not read file - bad permissions?";
        }
        exit();
    }

    /**
     * Returns array of wiki pages which will be included in the exported document
     *
     * @return array
     */
    public function getExportedPages() {
        return $this->list;
    }

    /**
     * usort callback to sort by file lastmodified time
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    public function _datesort($a, $b) {
        if($b['rev'] < $a['rev']) return -1;
        if($b['rev'] > $a['rev']) return 1;
        return strcmp($b['id'], $a['id']);
    }

    /**
     * usort callback to sort by page id
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    public function _pagenamesort($a, $b) {
        if($a['id'] <= $b['id']) return -1;
        if($a['id'] > $b['id']) return 1;
        return 0;
    }

}
