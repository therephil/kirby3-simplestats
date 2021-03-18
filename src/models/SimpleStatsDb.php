<?php

declare(strict_types=1);

namespace daandelange\SimpleStats;

use SQLite3;
//use ErrorException;
use Throwable;

use Kirby\Database\Database;
use Kirby\Toolkit\Collection;
use Kirby\Toolkit\F;
use Kirby\Toolkit\Obj;
use Kirby\Cms\Dir;

// todo : make it exception safe
// $db->query() can throw errors !

// This class is inspired from bnomei/pageviewcounter/classes/PageViewCounterSQLite.php
class SimpleStatsDb
{
    private static $database = null; // Db singleton

    // Db version needed for this script
    const engineDbVersion = 3;
    // 1    From releasedate -> jan 2021     Initial version
    // 2    From jan 2021    -> jan 2021     Added language tracking, renamed some columns.
    // 3    From jan 2021    -> mar 2021     No db structure update. Content update only : replace translated slugs by id.

    public function __construct(){
        // Initialize db for later usage
        //if( $this->database == null ){}
    }

    protected static function database(): Database {
        if(self::$database == null){
            self::createDBInstance();
        }
        return self::$database;
    }

    private static function createDBInstance() : bool {
        $target = self::getDbFile();

        // Initially create the db, if it doesn't exist yet.
        if (!F::exists($target)) {

            // Ensure the folder exists
            $dir = dirname($target);
            if (is_dir($dir) === false) {
                Dir::make($dir);
            }

            // Create db file
            try {
                $db = new SQLite3($target);
            } catch (Throwable $e) {
                Logger::logWarning('Error creating SQLite3 instance. Error='.$e->getMessage());
                return false;
            }

            // pre-compute languages string
            $langKeys = '';
            if( !kirby()->multilang() ){
                $langKeys = '`hits_en` INTEGER';
            }
            else {
                foreach( kirby()->languages() as $language ){
                    if( strlen($langKeys) > 0 ) $langKeys .= ', ';
                    $langKeys .= '`hits_'.$language->code().'` INTEGER';
                }
            }

            // Create db structure
            // todo: rename *.monthyear         --> periodid (except pagevisitors)
            $db->exec("CREATE TABLE IF NOT EXISTS `pagevisitors` (`userunique` TEXT primary key unique, `timeregistered` INTEGER, `osfamily` TEXT, `devicetype` TEXT, `browserengine` TEXT, `visitedpages` TEXT)");
            //$db->exec("CREATE TABLE IF NOT EXISTS `languagevisits` (`id` INTEGER primary key unique, ${$langKeys}");
            $db->exec("CREATE TABLE IF NOT EXISTS `referers` (`id` INTEGER primary key unique, `referer` TEXT, `domain` TEXT, `medium` TEXT, `monthyear` INTEGER, `hits` INTEGER)");
            $db->exec("CREATE TABLE IF NOT EXISTS `pagevisits` (`id` INTEGER primary key unique, `uid` TEXT, `monthyear` INTEGER, `hits` INTEGER, ${langKeys})");
            $db->exec("CREATE TABLE IF NOT EXISTS `devices` (`id` INTEGER primary key unique, `device` TEXT, `monthyear` INTEGER, `hits` INTEGER)");
            $db->exec("CREATE TABLE IF NOT EXISTS `engines` (`id` INTEGER primary key unique, `engine` TEXT, `monthyear` INTEGER, `hits` INTEGER)");
            $db->exec("CREATE TABLE IF NOT EXISTS `systems` (`id` INTEGER primary key unique, `system` TEXT, `monthyear` INTEGER, `hits` INTEGER)");
            $db->exec("CREATE TABLE IF NOT EXISTS `simplestats` (`id` INTEGER primary key unique, `version` INTEGER, `migrationdate` INTEGER)");
            $db->exec("INSERT INTO `simplestats` (`id`, `version`, `migrationdate`) VALUES (NULL, ".self::engineDbVersion.", ".date('Ymd').")");
            $db->close();


            // Double check
            if(!F::exists($target)){
                Logger::LogWarning("Error creating database @ ${target} ! SimpleStats will not work.");
                return false;
            }
            else {
                Logger::LogNotice("Successfully created a new SimpleStats database v_".self::engineDbVersion." @ ${target} !");
            }
        }

        // Initialize db for later usage
        if( self::$database == null ){
            try {
                self::$database = new Database([
                    'type' => 'sqlite',
                    'database' => $target,
                ]);
            } catch (Throwable $e) {
                Logger::logWarning('Error creating the DataBase singleton. Error='.$e->getMessage());
                //self::$database=null;
            }

            if(self::$database===null){
                Logger::LogWarning("Error loading database @ ${target} ! SimpleStats will not work.");
                return false;
            }
        }

        // Tmp here, to be ran from the panel
        //self::checkUpgradeDatabase(false);

        return true;
    }

    public static function getDbFile() : String {
        // Get DB file
        $target = option('daandelange.simplestats.tracking.database', false);

        // Override the setting if it aint an .sqlite file
        if( !$target || F::extension($target)!='sqlite'){
            // Note, the log root is not available in early k3 distributions, so use a fallback
            $target = self::getLogsPath('simplestats.sqlite');
            Logger::LogVerbose("Config --> db file replaced by default = ${target}.");
        }
        return $target;
    }

    public static function getLogsPath($file='') : String {
        return ( kirby()->roots()->logs()!==NULL ?
            kirby()->root('logs').'/'.$file :
            kirby()->root('config').'/../logs/'.$file );
    }

    public static function checkUpgradeDatabase( bool $dryRun = true ) : bool {
        $ret = true;
        // Update old databases ?
        if( $db = self::database() ){

            // Compare db version with software version, update if needed
            $dbVersionQ = $db->query("SELECT * FROM `simplestats` ORDER BY `version` DESC LIMIT 10");
            if(!$dbVersionQ){
                // v1 didn't have the simplestats table (only way to detect)
                if( stripos($db->lastError()->getMessage(), 'no such table:') !== false && stripos($db->lastError()->getMessage(), 'simplestats') !== false ){

                    if($dryRun){
                        // todo
                    }
                    // Upgrade
                    else {
                        $target = self::getDbFile();

                        // SQL
                        $dbsql3 = new SQLite3($target);

                        // Create simplestats version table (since v2)
                        if(
                            !$dbsql3->exec("CREATE TABLE IF NOT EXISTS `simplestats` (`id` INTEGER primary key unique, `version` INTEGER, `migrationdate` INTEGER)") ||
                            !$dbsql3->exec("INSERT INTO `simplestats` (`id`, `version`, `migrationdate`) VALUES (NULL, 2, ".date('Ymd').")")
                        ){
                            Logger::LogWarning("UPGRADE from db v1 to v2+ FAILED creating the simplestats table. Error=".$dbsql3->lastErrorMsg() );
                            @$dbsql3->close();
                            return false;
                        }
                        else {
                            Logger::LogNotice("UPGRADE from db v1 to v2+ COMPLETE !");

                            // Languages were also added in v2, but the global lang check will fix that
                        }

                        $dbsql3->close();

                        // todo: check if $db needs to reload the modified $dbsql3 ?
                        // re-query db to continue
                        $dbVersionQ = $db->query("SELECT `version` FROM `simplestats` ORDER BY `version` DESC LIMIT 1");
                    }
                }
            }
            // Still failed ?
            if(!$dbVersionQ){
                if($dryRun){
                    // todo
                }
                else {
                    Logger::LogWarning("Could not verify existing db version. Error=".$db->lastError()->getMessage());
                }
                $ret = false;
                //return false;// Don't return, global checks cans still proceed
            }
            // From here we have a version number, at least v2
            else{

                // General checks, version independant

                // check if all current website languages have their columns in the pagevisits table
                // (Used when the amount of website languages changes)
                if( $langsQ = $db->query("SELECT * FROM `pagevisits` LIMIT 1") ){ // or use "PRAGMA table_info(pagevisits);" ?

                    if( $langsQ->isNotEmpty() ){
                        $langs = $langsQ->first()->toArray();
                        $missingLangs = [];

                        // Compose missing langs
                        if( !kirby()->multilang() ){
                            // Tocheck: should be default lang ?
                            if( array_key_exists('hits_en', $langs) ){
                                $missingLangs[]='en';
                            }
                        }
                        else {
                            foreach( kirby()->languages() as $language ){
                                if( !array_key_exists('hits_'.$language->code(), $langs) ){
                                    $missingLangs[]=$language->code();
                                }
                            }
                        }

                        // Add any missing
                        if(count($missingLangs)>0){

                            if($dryRun){
                                // todo
                            }
                            // Upgrade langs
                            else {
                                $target = self::getDbFile();
                                Logger::LogVerbose('UPGRADE db, adding LANGUAGES '.implode(', ', $missingLangs).' to pagevisits.');

                                // SQL
                                $dbsql3 = new SQLite3($target);

                                foreach($missingLangs as $l){
                                    // Note : ALTER TABLE cannot add several columns in 1 command.
                                    if( !$dbsql3->exec('ALTER TABLE `pagevisits` ADD COLUMN `hits_'.$l.'` INTEGER') ){
                                        Logger::LogWarning("UPGRADE db, adding LANGUAGES FAILED creating columns for ${l}. Error=".$dbsql3->lastError()->getMessage());
                                        $dbsql3->close();
                                        //return true;
                                        $ret = false;
                                    }
                                    else {
                                        Logger::LogNotice("UPGRADE db ADDED LANGUAGE = ${l}.");
                                    }
                                }
                                $dbsql3->close();
                            }
                        }

                    }
                    else{
                        Logger::LogWarning("No pagevisits entries yet, cannot update db !");
                        $ret=false;
                    }
                }
                else {
                    Logger::LogWarning("Db Upgrade, could not check languages! Error=".$db->lastError()->getMessage());
                    $ret = false;
                }

                // From version 2 and up, check for version match
                if($dbVersionQ->isNotEmpty()){
                    $dbVersion = intval($dbVersionQ->first()->version, 10);
                    // Compare version
                    if( self::engineDbVersion !== $dbVersion ){
                        Logger::LogVerbose("Upgrade : Detected 2 different db versions ! [required=".self::engineDbVersion." / current=".$dbVersion."] Starting upgrade process...");

                        if($dryRun){
                            // todo
                        }
                        // Upgrade the database to the newest version
                        else {
                            // Upgrade processes v2 -> v3
                            if( $dbVersion < 3){
                                //$ret=true;

                                // v2 was using $page->slug() instead of $page->id(), causing unique page IDs to be translated. Untranslate them.
                            	$langs = kirby()->languages()->toArray( function($v){return $v->code();} );
                            	$defaultlang = kirby()->defaultLanguage()->code();
                            	$slugsToRestore = [];
                            	// Grap all pages and build a translations array
                            	foreach( site()->index() as $p ){
                            		foreach($langs as $l){
                            			$from = $p->slug($l);
                            			$to = $p->slug($defaultlang);
                            			if($from !== $to){
                            				$slugsToRestore[] = ['from'=>$from, 'to'=>$to];
                            				//var_dump($from.' --> '.$to);
                            			}
                            		}
                            	}
                            	$renameQuery = '';
                            	foreach($slugsToRestore as $slug){
                            		$renameQuery .= 'UPDATE `pagevisits` SET `uid` = "'.$slug['to'].'" WHERE `uid` = "'.$slug['from'].'"; ';
                            	}
                            	if( $renameResult = $db->execute($renameQuery) ){
                                	// ok
                                	Logger::LogVerbose("Upgrade : Renamed translated slugs for v2->v3.");
                                }
                                else {
                                    $ret = false;
                                    Logger::LogWarning("Upgrade : Error renaming translated slugs for v2->v3. Error=".$db->lastError()->getMessage());
                                }

                            	// Select duplicates
                            	$selectDuplicates = 'SELECT min(`id`) as "idtokeep", `uid`, COUNT(`id`)  as "numentries", `monthyear`, SUM(`hits_fr`) AS "newhits_fr", SUM(`hits`) as "newhits", SUM(`hits_en`) AS "newhits_en" FROM `pagevisits` GROUP BY  uid, monthyear HAVING COUNT(`id`) > 1 LIMIT 10000;';

                            	// For each duplicate, merge hits and only keep the one with the lowest ID.
                            	if($duplicatesResult = $db->query($selectDuplicates)){
                            		$mergeUpdateQuery = '';
                            		$mergeDeleteQuery = '';
                            		if($duplicatesResult->isNotEmpty()){
                            			$duplicates = $duplicatesResult->toArray();//function($item){ return ['idtokeep'=>$item->idtokeep,'uid'=>$item->uid];});
                            			//var_dump($duplicates);
                            			foreach($duplicates as $d){
                            				//var_dump($d);
                            				$mergeUpdateQuery .= 'UPDATE `pagevisits` SET `hits` = '.$d->newhits.', `hits_fr` = '.$d->newhits_fr.', `hits_en` = '.$d->newhits_en.' WHERE `id`='.$d->idtokeep.'; ';
                            				$mergeDeleteQuery .= 'DELETE FROM `pagevisits` WHERE `uid` = "'.$d->uid.'" AND `monthyear` = '.$d->monthyear.' AND `id` != '.$d->idtokeep.'; ';// LIMIT '.(intval($d->numentries,10)-1).'; ';

                            			}
                            		}

                                    $changeToV3 = false;
                                    if(!empty($mergeDeleteQuery) || !empty($mergeUpdateQuery)){
                                        if( $updateResult = $db->execute($mergeUpdateQuery) ){

                                            // Note: without updating, dont proceed to deletion !
                                            if( $deleteResult = $db->execute($mergeDeleteQuery) ){
                                                // ok
                                                Logger::LogVerbose("Upgrade : v2 to v3 was successful ! (translated pagevisits have been merged)");
                                                $changeToV3 = true;
                                            }
                                            else {
                                                Logger::LogWarning("Db Upgrade, could not delete duplicate entries for v2->v3! Error=".$db->lastError()->getMessage());
                                                $ret = false;
                                            }
                                        }
                                        else {
                                            Logger::LogWarning("Db Upgrade, could not update duplicate entries for v2->v3! Error=".$db->lastError()->getMessage());
                                            $ret = false;
                                        }

                                    }
                                    else {
                                        Logger::LogVerbose("Upgrade : v2 to v3 was successful (no duplicates were updated) !");
                                        $changeToV3 = true;
                                    }

                                    // Update db version info
                                    if($changeToV3){
                                        if(!$db->execute("INSERT INTO `simplestats` (`id`, `version`, `migrationdate`) VALUES (NULL, 3, ".date('Ymd').")")){
                                            Logger::LogWarning("Db Upgrade, could not change db version to v3! Error=".$db->lastError()->getMessage());
                                            $ret = false;
                                        }
                                    }
                            	}
                            	else {
                            		// todo: log
                            		$ret = false;
                            	}

                            	// Upgrade version
                            	//$dbsql3->exec("INSERT INTO `simplestats` (`id`, `version`, `migrationdate`) VALUES (NULL, 3, ".date('Ymd').")")
                            } // End v2 -> v3
                            //$ret=false;
                        }
                    }
                    else {
                        Logger::LogVerbose("Upgrade : Versions are identical :)");
                    }
                }
                else {
                    Logger::LogWarning("Could not verify existing db version : Error parsing the version number.");
                    $ret=false;
                }
            }


        }
        return $ret;
    }

}
