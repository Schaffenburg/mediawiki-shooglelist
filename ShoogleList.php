<?php
  if (!defined('MEDIAWIKI')) die();
 
  $wgExtensionFunctions[] = 'wfShoogleList';
  $wgExtensionCredits['parserhook'][] = array(
    'path'            => __FILE__,
    'name'            => 'ShoogleList',
    'version'         => '0.00001',
    'author'          => 'Schinken',
    'url'             => 'http://www.hackerspace-bamberg.de',
    'description'     => 'Generates a category list in Shoogle-Style'  );
 

 // Register parser-hook
 function wfShoogleList() {
    new ShoogleList();
 }
 
 
class ShoogleList {
 
  // Default configuration
  private $settings = array(
    'sort' => true
  );
 
  public function __construct() {
    global $wgParser;
    $wgParser->setHook('shoogle', array(&$this, 'hookShoogle'));
  }
 

  public function hookShoogle($category, $argv, $parser) {

    $parser->disableCache();

    // Merge user specific settings with own defaults
    $this->settings = array_merge($this->settings, $argv);

    $localParser = new Parser();
    $category = $localParser->preprocess($category, $parser->mTitle, $parser->mOptions, false);

    $defaultImg = '';
    if( isset( $argv['defaultimg'] ) ) {
        $defaultImg = $argv['defaultimg'];    
    }

    $defaultDesc = '';
    if( isset( $argv['defaultdesc'] ) ) {
        $defaultDesc = $argv['defaultdesc'];    
    }

    $title = Title::newFromText($category);

    $Articles = $this->getArticlesByCategory($title);

    $output = '<div class="shoogle-box">';
    $output .= '<ul class="shoogle-list clearfix">';

    foreach( $Articles as $Article ) {

        $Content    = $Article->getContent();
        $Title      = $Article->getTitle();

        $projectTag = '';
        if( preg_match('/{{Infobox Projekt(.*)}}/s', $Content, $m ) ) {
            $projectTag = $m[1];
        }

            $attr = array(
                'name'          => $Title,
                'image'         => $defaultImg,
                'beschreibung'  => $defaultDesc
            );

            foreach( $attr as $key => $value ) {
                if( preg_match('/\|'.$key.'\s*=(.*)$/m', $projectTag, $m ) ) {
                    $val = trim( $m[1] );
                    if( !empty( $val ) ) {
                        $attr[ $key ] = $val;
                    }
                }
            }


            $output .= '<li class="shoogle-item">';
            $output .= sprintf('<span class="shoogle-title">[[%s|%s]]</span>', $Title, $attr['name'] );
            $output .= sprintf('<span class="shoogle-image">[[Image:%s|180px|link=%s|alt=%s]]</span>', $attr['image'], $Title, $Title );
            $output .= sprintf('<span class="shoogle-teaser">%s</span>', $attr['beschreibung'] );
            $otuput .= '</li>';
    }


    $output .= '</ul>';
    $output .= '</div>';
    $output .= "__NOTOC__\n";

    global $wgOut, $wgScriptPath;
    $wgOut->addExtensionStyle("{$wgScriptPath}/extensions/ShoogleList/ShoogleList.css");

    $localParser = new Parser();
    $output = $localParser->parse($output, $parser->mTitle, $parser->mOptions);
    return $output->getText();
 
  }

 
    private function getArticlesByCategory($Title) {

        $dbr = wfGetDB(DB_SLAVE);

        // query database
        $res = $dbr->select(
            array('page', 'categorylinks'),
            array('page_title', 'page_namespace', 'cl_sortkey'),
            array('cl_from = page_id', 'cl_to' => $Title->getDBKey()),
            array('ORDER BY' => 'cl_sortkey')
        );

        if( $res === false ) {
            return array();
        }
 
        // convert results list into an array
        $Articles = array();
        while ( $Article = $dbr->fetchRow( $res ) ) {
            $Title = Title::makeTitle( $Article['page_namespace'], $Article['page_title'] );
            if ( $Title->getNamespace() != NS_CATEGORY ) {
               $Articles[] = new ShoogleList_Article( $Title );
            }
        }
 
        // free the results
        $dbr->freeResult($res);
 
        return $Articles;
    }
 
}
 
class ShoogleList_Article {

  private $Title   = null;
  private $Article = null;

  public function __construct( $Title ) {
    $this->Title = $Title;
    $this->Article = new Article( $Title );
  }
 
  public function getTitle() {
    return $this->Title;
  }
 
  public function getContent() {
    return $this->Article->getContent();       
  }
 
}
