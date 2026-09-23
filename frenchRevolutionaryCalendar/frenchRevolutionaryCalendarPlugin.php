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
			// Renvoyer false ici avorterait la chaine de hooks pour TOUS les plugins
			// (ApplicationPluginManager::__call) et ferait remonter une expression nulle
			// au TimeExpressionParser. On rend toujours les parametres inchanges.
			if(!$this->opo_config->get('enabled')) { return $pa_params; }
			if(!isset($pa_params["expression"])) { return $pa_params; }

			$vb_month_comes_first = $this->opo_language_settings->get('monthComesFirstInDelimitedDate');

			// ---------------------------------------------------------------
			// 1. Nettoyage prealable
			//    $vs_expression porte desormais TOUJOURS l'expression nettoyee :
			//    les sorties anticipees ci-dessous conservent donc le nettoyage
			//    de maniere intentionnelle, et non par effet de bord.
			// ---------------------------------------------------------------
			$vs_expression = $pa_params["expression"];

			// Retrait des crochets (dates incertaines des notices bibliographiques)
			if (((bool)$this->opo_config->get('removeSquareBrackets'))) {
				$vs_expression = str_replace(array('[',']'),'',$vs_expression);
			}

			// Retrait des prefixes bibliographiques (COP, DL, IMPR...)
			if(is_array($va_keywords = $this->opo_config->get('removeKeywords')) && sizeof($va_keywords)) {
				// Tri du plus long au plus court : str_replace applique le tableau dans
				// l'ordre, donc "COP" retire avant "COP." laisse un point orphelin, et
				// ". 1950" est alors lu comme "avant 1950" (plage ouverte erronee).
				usort($va_keywords, function($a, $b) { return mb_strlen((string)$b) - mb_strlen((string)$a); });
				$vs_expression = str_replace($va_keywords,'',$vs_expression);
			}

			$pa_params["expression"] = $vs_expression;

			// ---------------------------------------------------------------
			// 2. Ramasse-miettes "non date" (optionnel, desactive par defaut)
			//    Une expression qui n'est QUE un marqueur d'absence de date
			//    ("?", "s.d.", "sans date"...) est remplacee par un token que le
			//    fichier .lang de la locale sait lire comme "undated" : le parse
			//    reussit, aucune borne n'est produite, rien n'est indexe.
			// ---------------------------------------------------------------
			if(((bool)$this->opo_config->get('normalizeUndated'))) {
				if(is_array($va_markers = $this->opo_config->get('undatedMarkers')) && sizeof($va_markers)) {
					$va_quoted = array();
					foreach($va_markers as $vs_marker) {
						$vs_marker = trim((string)$vs_marker);
						if (!strlen($vs_marker)) { continue; }
						$va_quoted[] = preg_quote($vs_marker, '!');
					}
					if (sizeof($va_quoted)) {
						// Ancrage sur l'expression ENTIERE. Indispensable : un simple
						// str_replace amputerait "1950?" (= circa 1950) ainsi que toute
						// cote ou mention contenant "sd" ou "nd".
						$vs_pattern = '!^\s*(?:'.implode('|', $va_quoted).')\s*$!ui';
						if (preg_match($vs_pattern, $vs_expression)) {
							$vs_token = trim((string)$this->opo_config->get('undatedToken'));
							if (!strlen($vs_token)) { $vs_token = 'undated'; }
							$pa_params["expression"] = $vs_token;
							return $pa_params;
						}
					}
				}
			}

			// ---------------------------------------------------------------
			// 3. Calendrier revolutionnaire
			// ---------------------------------------------------------------
			$vs_month_exp = implode("|",$this->opa_monthnames);

			// Pas de mois revolutionnaire dans l'expression : sortie directe
			if(!preg_match("/".$vs_month_exp."/i",$vs_expression)) { return $pa_params; }

			// Expression en deux parties (plage) ?
			if(strpos($vs_expression, "-") !== false) {
				$va_expression = explode("-",$vs_expression);
			} else {
				$va_expression= array($vs_expression);
			}

			foreach($va_expression as $num=>$vs_expression_part) {

				$va_results = array();
				if(!preg_match("/(?:(?<day>\d{1,2}) )?(?<month>".$vs_month_exp.") (?:an )?(?<year_roman>[IXVLCDM]+)?(?<year_dec>[0-9]+)?/i",$vs_expression_part,$va_results)) {
					// Capture impossible : on rend l'expression nettoyee telle quelle
					// plutot que de fabriquer une date fausse.
					return $pa_params;
				}

				// Le jour est facultatif ("germinal an V") : a defaut, 1er du mois.
				// Sans ce garde-fou, frenchtojd() recevait une chaine vide et levait
				// un TypeError fatal sous PHP 8.
				$vn_day = (isset($va_results["day"]) && strlen($va_results["day"])) ? (int)$va_results["day"] : 1;

				// L'annee peut etre en chiffres romains ("an V") ou en decimal ("an 5")
				$vs_year_roman = $va_results["year_roman"] ?? '';
				$vs_year_dec   = $va_results["year_dec"] ?? '';
				$vn_year = (int)(caRomanArabic($vs_year_roman) ?: $vs_year_dec);

				// array_search est sensible a la casse : sans normalisation, "Germinal"
				// ne serait pas trouve et retomberait silencieusement sur vendemiaire.
				$vn_month_idx = array_search(mb_strtolower((string)$va_results["month"], 'UTF-8'), $this->opa_monthnames);
				if ($vn_month_idx === false) { return $pa_params; }
				$vn_month = (int)$vn_month_idx + 1;

				if ($vn_year <= 0) { return $pa_params; }

				$vs_gregorian_date = jdtogregorian(frenchtojd($vn_month, $vn_day, $vn_year));

				// jdtogregorian rend toujours le mois en premier ; on reordonne si la
				// locale attend le jour en premier.
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

			return $pa_params;
		}
		# -------------------------------------------------------
		static public function getRoleActionList() {
			return array();
		}
		# -------------------------------------------------------
	}
