<?php
/* ----------------------------------------------------------------------
 * frenchRevolutionaryCalendarPlugin.php :
 * ----------------------------------------------------------------------
 * Plugin pour CollectiveAccess Providence
 *
 * Convertit les dates exprimees dans le calendrier revolutionnaire francais
 * (vendemiaire, brumaire, frimaire... an X) en dates gregoriennes,
 * en preprocess du TimeExpressionParser de CollectiveAccess.
 *
 * Hook utilise : hookTimeExpressionParserPreprocessAfter
 *
 * Distribue sous GNU General Public License v3.0.
 * ----------------------------------------------------------------------
 */
require_once(__CA_LIB_DIR__."/Parsers/TimeExpressionParser.php");

class frenchRevolutionaryCalendarPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		private $opa_monthnames;

		private $opo_language_settings;

		public $description;

		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			global $g_ui_locale;

			$this->description = _t('Handles French Revolutionary calendar dates as input for CollectiveAccess TimeExpressionParser');
			$this->ops_plugin_path = $ps_plugin_path;
			parent::__construct();

			$conf_file = $ps_plugin_path.'/conf/local/frenchRevolutionaryCalendar.conf';
			if (!file_exists($conf_file)) {
				$conf_file = $ps_plugin_path.'/conf/frenchRevolutionaryCalendar.conf';
			}
			$this->opo_config = Configuration::load($conf_file);

			$this->opa_monthnames = array(
				"vendemiaire",
				"brumaire",
				"frimaire",
				"nivose",
				"pluviose",
				"ventose",
				"germinal",
				"floreal",
				"prairial",
				"messidor",
				"thermidor",
				"fructidor",
				"sansculottide"
			);

			$ps_iso_code = $g_ui_locale;
			if (!$ps_iso_code) { $ps_iso_code = 'en_US'; }
			if (file_exists(__CA_LIB_DIR__.'/Parsers/TimeExpressionParser/'.$ps_iso_code.'.lang')) {
				$this->opo_language_settings = Configuration::load(__CA_LIB_DIR__.'/Parsers/TimeExpressionParser/'.$ps_iso_code.'.lang');
			} else {
				die("Could not load language '$ps_iso_code'");
			}
		}
		# -------------------------------------------------------
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => ((bool)$this->opo_config->get('enabled'))
			);
		}
		# -------------------------------------------------------
		/**
		 * Preprocess a date expression: detect French Revolutionary month names
		 * and convert them to Gregorian dates before the TimeExpressionParser parses them.
		 */
		public function hookTimeExpressionParserPreprocessAfter(array $pa_params=array()) {
			if(!$this->opo_config->get('enabled')) return false;

			$vb_month_comes_first = $this->opo_language_settings->get('monthComesFirstInDelimitedDate');

			if(isset($pa_params["expression"])) {

				$vs_month_exp = implode("|",$this->opa_monthnames);

				$vs_expression=$pa_params["expression"];

				// removing square brackets if needed
				if (((bool)$this->opo_config->get('removeSquareBrackets'))) {
					$pa_params["expression"] = str_replace(array('[',']'),'',$pa_params["expression"]);
				}
				// removing COP, DL, IMPR... if wanted
				if(is_array($this->opo_config->get('removeKeywords'))) {
					$pa_params["expression"] = str_replace($this->opo_config->get('removeKeywords'),'',$pa_params["expression"]);
				}

				// if no month name inside the expression, direct exit
				if(!preg_match("/".$vs_month_exp."/i",$vs_expression)) return $pa_params;

				// If 2-parts expression (range)
				if(strpos($vs_expression, "-") !== false) {
					$va_expression = explode("-",$vs_expression);
				} else {
					$va_expression= array($vs_expression);
				}

				foreach($va_expression as $num=>$vs_expression_part) {

					preg_match("/(?:(?<day>\d{1,2}) )?(?<month>".$vs_month_exp.") (?:an )?(?<year_roman>[IXVLCDM]+)?(?<year_dec>[0-9]+)?/i",$vs_expression_part,$va_results);

					// Year can be typed as a Roman numeral or as a decimal, with optional "an" keyword
					$vi_year = (caRomanArabic($va_results["year_roman"]) ? : $va_results["year_dec"]);
					$vs_gregorian_date = jdtogregorian(
						frenchtojd(
							(array_search($va_results["month"], $this->opa_monthnames)+ 1),
							$va_results["day"],
							$vi_year
						)
					);

					// jdtogregorian always returns month first; reorder if the locale expects day first
					if(!$vb_month_comes_first) {
						$va_date_parts=explode("/",$vs_gregorian_date);
						$vs_gregorian_date=$va_date_parts[1]."/".$va_date_parts[0]."/".$va_date_parts[2];
					}

					$va_expression[$num] = $vs_gregorian_date;
				}

				if (sizeof($va_expression)>1) {
					$vs_expression=implode(" - ",$va_expression);
				} else {
					$vs_expression=$va_expression[0];
				}
				$pa_params["expression"] = $vs_expression;
			}
			return $pa_params;
		}

		# -------------------------------------------------------
		static public function getRoleActionList() {
			return array();
		}
		# -------------------------------------------------------
	}
