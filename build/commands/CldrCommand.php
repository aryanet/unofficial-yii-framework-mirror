<?php
/**
 * CldrCommand class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2011 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 * @version $Id$
 */

/**
 * CldrCommand converts the locale data from the {@link http://www.unicode.org/cldr/ CLDR project}
 * to PHP scripts so that they can be more easily used in PHP programming.
 *
 * The script respects locale inheritance so that the PHP data for a child locale
 * will contain all its parents' locale data if they are not specified in the child locale.
 * Therefore, to import the data for a locale, only the PHP script for that particular locale
 * needs to be included.
 *
 * Note, only the data relevant to number and date formatting are extracted.
 * Each PHP script file is named as the corresponding locale ID in lower case.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @version $Id$
 * @package system.build
 * @since 1.0
 */
class CldrCommand extends CConsoleCommand
{
	public function getHelp()
	{
		return <<<EOD
USAGE
  build cldr <data-path>

DESCRIPTION
  This command converts the locale data from the CLDR project
  to PHP scripts so that they can be more easily used in PHP programming.

  The script respects locale inheritance so that the PHP data for
  a child locale will contain all its parent locale data if they are
  not specified in the child locale. Therefore, to import the data
  for a locale, only the PHP script for that particular locale needs
  to be included.

  Note, only the data relevant to number and date formatting are extracted.
  Each PHP script file is named as the corresponding locale ID in lower case.

  The resulting PHP scripts are created under the same directory that
  contains the original CLDR data.

PARAMETERS
 * data-path: required, the original CLDR data directory. This
   directory should contain hundreds of XML files.

EOD;
	}

	/**
	 * Execute the action.
	 * @param array command line parameters specific for this command
	 */
	public function run($args)
	{
		if(!isset($args[0]))
			$this->usageError('the CLDR data directory is not specified.');
		if(!is_dir($path=$args[0]))
			$this->usageError("directory '$path' does not exist.");

		// collect XML files to be processed
		$options=array(
			'exclude'=>array('.svn'),
			'fileTypes'=>array('xml'),
			'level'=>0,
		);
		$files=CFileHelper::findFiles(realpath($path),$options);
		$sourceFiles=array();
		foreach($files as $file)
			$sourceFiles[basename($file)]=$file;

		// sort by file name so that inheritances can be processed properly
		ksort($sourceFiles);

		// process root first because it is inherited by all
		if(isset($sourceFiles['root.xml']))
		{
			$this->process($sourceFiles['root.xml']);
			unset($sourceFiles['root.xml']);

			foreach($sourceFiles as $sourceFile)
				$this->process($sourceFile);
		}
		else
			die('Unable to find the required root.xml under CLDR data directory.');
	}

	protected function process($path)
	{
		$source=basename($path);
		echo "processing $source...";

		$dir=dirname($path);
		$locale=substr($source,0,-4);
		$target=$locale.'.php';

		// retrieve parent data first
		if(($pos=strrpos($locale,'_'))!==false)
			$data=require($dir.DIRECTORY_SEPARATOR.substr($locale,0,$pos).'.php');
		else if($locale!=='root')
			$data=require($dir.DIRECTORY_SEPARATOR.'root.php');
		else
			$data=array();

		$xml=simplexml_load_file($path);

		$this->parseVersion($xml,$data);

		$this->parseNumberSymbols($xml,$data);
		$this->parseNumberFormats($xml,$data);
		$this->parseCurrencySymbols($xml,$data);

		$this->parseMonthNames($xml,$data);
		$this->parseWeekDayNames($xml,$data);
		$this->parseEraNames($xml,$data);

		$this->parseDateFormats($xml,$data);
		$this->parseTimeFormats($xml,$data);
		$this->parseDateTimeFormat($xml,$data);
		$this->parsePeriodNames($xml,$data);

		$this->parseOrientation($xml,$data);

		$data=str_replace("\r",'',var_export($data,true));
		$locale=substr(basename($path),0,-4);
		$content=<<<EOD
/**
 * Locale data for '$locale'.
 *
 * This file is automatically generated by yiic cldr command.
 *
 * Copyright © 1991-2007 Unicode, Inc. All rights reserved.
 * Distributed under the Terms of Use in http://www.unicode.org/copyright.html.
 *
 * Copyright © 2008-2011 Yii Software LLC (http://www.yiiframework.com/license/)
 */
return $data;
EOD;

		file_put_contents($dir.DIRECTORY_SEPARATOR.strtolower($locale).'.php',"<?php\n".$content."\n");

		echo "done.\n";
	}

	protected function parseVersion($xml,&$data)
	{
		preg_match('/[\d\.]+/',(string)$xml->identity->version['number'],$matches);
		$data['version']=$matches[0];
	}

	protected function parseNumberSymbols($xml,&$data)
	{
		foreach($xml->xpath('/ldml/numbers/symbols/*') as $symbol)
		{
			$name=$symbol->getName();
			if(!isset($data['numberSymbols'][$name]) || (string)$symbol['draft']==='')
				$data['numberSymbols'][$name]=(string)$symbol;
		}
	}

	protected function parseNumberFormats($xml,&$data)
	{
		$pattern=$xml->xpath('/ldml/numbers/decimalFormats/decimalFormatLength/decimalFormat/pattern');
		if(isset($pattern[0]))
			$data['decimalFormat']=(string)$pattern[0];
		$pattern=$xml->xpath('/ldml/numbers/scientificFormats/scientificFormatLength/scientificFormat/pattern');
		if(isset($pattern[0]))
			$data['scientificFormat']=(string)$pattern[0];
		$pattern=$xml->xpath('/ldml/numbers/percentFormats/percentFormatLength/percentFormat/pattern');
		if(isset($pattern[0]))
			$data['percentFormat']=(string)$pattern[0];
		$pattern=$xml->xpath('/ldml/numbers/currencyFormats/currencyFormatLength/currencyFormat/pattern');
		if(isset($pattern[0]))
			$data['currencyFormat']=(string)$pattern[0];
	}

	protected function parseCurrencySymbols($xml,&$data)
	{
		$currencies=$xml->xpath('/ldml/numbers/currencies/currency');
		foreach($currencies as $currency)
		{
			if((string)$currency->symbol!='')
				$data['currencySymbols'][(string)$currency['type']]=(string)$currency->symbol;
		}
	}

	protected function parseMonthNames($xml,&$data)
	{
		$monthTypes=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/months/monthContext[@type=\'format\']/monthWidth');
		if(is_array($monthTypes))
		{
			foreach($monthTypes as $monthType)
			{
				$names=array();
				foreach($monthType->xpath('month') as $month)
					$names[(string)$month['type']]=(string)$month;
				if($names!==array())
					$data['monthNames'][(string)$monthType['type']]=$names;
			}
		}

		if(!isset($data['monthNames']['abbreviated']))
			$data['monthNames']['abbreviated']=$data['monthNames']['wide'];

		$monthTypes=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/months/monthContext[@type=\'stand-alone\']/monthWidth');
		if(is_array($monthTypes))
		{
			foreach($monthTypes as $monthType)
			{
				$names=array();
				foreach($monthType->xpath('month') as $month)
					$names[(string)$month['type']]=(string)$month;
				if($names!==array())
					$data['monthNamesSA'][(string)$monthType['type']]=$names;
			}
		}
	}

	protected function parseWeekDayNames($xml,&$data)
	{
		static $mapping=array(
			'sun'=>0,
			'mon'=>1,
			'tue'=>2,
			'wed'=>3,
			'thu'=>4,
			'fri'=>5,
			'sat'=>6,
		);
		$dayTypes=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/days/dayContext[@type=\'format\']/dayWidth');
		if(is_array($dayTypes))
		{
			foreach($dayTypes as $dayType)
			{
				$names=array();
				foreach($dayType->xpath('day') as $day)
					$names[$mapping[(string)$day['type']]]=(string)$day;
				if($names!==array())
					$data['weekDayNames'][(string)$dayType['type']]=$names;
			}
		}

		if(!isset($data['weekDayNames']['abbreviated']))
			$data['weekDayNames']['abbreviated']=$data['weekDayNames']['wide'];

		$dayTypes=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/days/dayContext[@type=\'stand-alone\']/dayWidth');
		if(is_array($dayTypes))
		{
			foreach($dayTypes as $dayType)
			{
				$names=array();
				foreach($dayType->xpath('day') as $day)
					$names[$mapping[(string)$day['type']]]=(string)$day;
				if($names!==array())
					$data['weekDayNamesSA'][(string)$dayType['type']]=$names;
			}
		}
	}

	protected function parsePeriodNames($xml,&$data)
	{
		$am=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/am');
		if(is_array($am) && isset($am[0]))
			$data['amName']=(string)$am[0];
		$pm=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/pm');
		if(is_array($pm) && isset($pm[0]))
			$data['pmName']=(string)$pm[0];
	}

	protected function parseEraNames($xml,&$data)
	{
		$era=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/eras/eraAbbr');
		if(is_array($era) && isset($era[0]))
		{
			foreach($era[0]->xpath('era') as $e)
				$data['eraNames']['abbreviated'][(string)$e['type']]=(string)$e;
		}

		$era=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/eras/eraNames');
		if(is_array($era) && isset($era[0]))
		{
			foreach($era[0]->xpath('era') as $e)
				$data['eraNames']['wide'][(string)$e['type']]=(string)$e;
		}
		else if(!isset($data['eraNames']['wide']))
			$data['eraNames']['wide']=$data['eraNames']['abbreviated'];

		$era=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/eras/eraNarrow');
		if(is_array($era) && isset($era[0]))
		{
			foreach($era[0]->xpath('era') as $e)
				$data['eraNames']['narrow'][(string)$e['type']]=(string)$e;
		}
		else if(!isset($data['eraNames']['narrow']))
			$data['eraNames']['narrow']=$data['eraNames']['abbreviated'];
	}

	protected function parseDateFormats($xml,&$data)
	{
		$types=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/dateFormats/dateFormatLength');
		if(is_array($types))
		{
			foreach($types as $type)
			{
				$pattern=$type->xpath('dateFormat/pattern');
				$data['dateFormats'][(string)$type['type']]=(string)$pattern[0];
			}
		}
	}

	protected function parseTimeFormats($xml,&$data)
	{
		$types=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/timeFormats/timeFormatLength');
		if(is_array($types))
		{
			foreach($types as $type)
			{
				$pattern=$type->xpath('timeFormat/pattern');
				$data['timeFormats'][(string)$type['type']]=(string)$pattern[0];
			}
		}
	}

	protected function parseDateTimeFormat($xml,&$data)
	{
		$types=$xml->xpath('/ldml/dates/calendars/calendar[@type=\'gregorian\']/dateTimeFormats/dateTimeFormatLength');
		if(is_array($types) && isset($types[0]))
		{
			$pattern=$types[0]->xpath('dateTimeFormat/pattern');
			$data['dateTimeFormat']=(string)$pattern[0];
		}
	}

	protected function parseOrientation($xml,&$data)
	{
		$orientation=$xml->xpath('/ldml/layout/orientation[@characters=\'right-to-left\']');
		if(!empty($orientation))
			$data['orientation']='rtl';
		else if(!isset($data['orientation']))
			$data['orientation']='ltr';
	}
}