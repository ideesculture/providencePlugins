<?php
/* ----------------------------------------------------------------------
 * plugins/statisticsViewer/controllers/StatisticsController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2010 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/TaskQueue.php');
require_once(__CA_LIB_DIR__.'/Configuration.php');
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');
require_once(__CA_MODELS_DIR__.'/ca_entities.php');

require_once(__CA_BASE_DIR__."/vendor/pear/file_marc/File/MARC.php");

class SimpleZ3950Controller extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;		// plugin configuration file
	protected $pa_parameters;

	# -------------------------------------------------------
	# Constructor
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

//		if (!$this->request->user->canDoAction('can_use_simple_z3950_plugin')) {
//			$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3000?r='.urlencode($this->request->getFullUrlPath()));
//			return;
//		}

		$conf_file       = __CA_APP_DIR__.'/plugins/SimpleZ3950/conf/SimpleZ3950.conf';
		$conf_file_local = __CA_APP_DIR__.'/plugins/SimpleZ3950/conf/local/SimpleZ3950.conf';
		if (file_exists($conf_file_local)) { $conf_file = $conf_file_local; }
		$this->opo_config = Configuration::load($conf_file);

	}

	# -------------------------------------------------------
	# Functions to render views
	# -------------------------------------------------------
	public function Index($type="") {
		// GET : $opa_stat=$this->request->getParameter('stat', pString);
		// SET : $this->view->setVar('queryparameters', $opa_queryparameters);
		if(!function_exists("yaz_connect")) {
			//var_dump("test");die();
			$this->view->setVar("message","L'extension PHP Yaz n'est pas disponible sur ce serveur.");
			$this->render('error_html.php');
		} else {
			$servers = $this->opo_config->get("servers");
			$this->view->setVar("servers",$servers);
			$this->render('index_html.php');
		}
	}

	# -------------------------------------------------------
	public function Lot($type="") {
		// GET : $opa_stat=$this->request->getParameter('stat', pString);
		// SET : $this->view->setVar('queryparameters', $opa_queryparameters);
		$this->render('lot_html.php');
	}

	public function Search() {
		$servers = $this->opo_config->get("servers");

		$ps_serveur=$this->request->getParameter('serveur', pString);
		$ps_action=$this->request->getParameter('action', pString);
		$ps_search=$this->request->getParameter('search', pString);
		
		$va_server = $servers[$ps_serveur];

		if(!function_exists("yaz_connect")) {
			$this->view->setVar("message","L'extension PHP Yaz n'est pas disponible sur ce serveur.");
			$this->render('error_html.php');
		} else {
			if($va_server["user"]) {
				$va_yaz_options=["user"=>$va_server["user"],"password"=>$va_server["password"]];	
			} else {
				$va_yaz_options= null;
			}
			
			$vs_zurl=$va_server["url"];
			if (!$va_yaz_options) {
				$vo_yaz_ressource = yaz_connect($vs_zurl);
			} else {
				$vo_yaz_ressource = yaz_connect($vs_zurl, $va_yaz_options);
			}
			yaz_syntax($vo_yaz_ressource, "unimarc");
			yaz_range($vo_yaz_ressource, 1, 10);

			$request = "@attr 1=".$va_server["attribute"]." "." \"". $ps_search . "\"";

			yaz_search($vo_yaz_ressource, "rpn", $request);
			yaz_wait();
	
			$error = yaz_error($vo_yaz_ressource);

			$files = $previews = $raws = $titles = [];
			$nb_hits = 0;

			if (!empty($error)) {
				$this->view->setVar('message', _t("Erreur Z39.50 : %1", $error));
				$this->render('error_html.php');
				return;
			}

			$nb_hits = yaz_hits($vo_yaz_ressource);
			// `yaz_range(1, 10)` plus haut limite à 10 enregistrements téléchargés ;
			// on borne donc explicitement la boucle pour éviter de demander des
			// records vides (ce qui, avec un `continue` sans `$i++`, aboutissait
			// à une boucle infinie → memory limit → 500).
			$max = min($nb_hits, 10);

			for ($i = 1; $i <= $max; $i++) {
				$rec         = yaz_record($vo_yaz_ressource, $i, "raw");
				$rec_display = yaz_record($vo_yaz_ressource, $i, "string");
				if (empty($rec)) { continue; }

				$target_file = __CA_APP_DIR__."/tmp/z3950_".str_replace([" ",",","!","/","\\"],"",$ps_search)."_".$i.".pan";
				file_put_contents($target_file, $rec);
				$files[] = $target_file;

				$marc = new File_MARC($rec, File_MARC::SOURCE_STRING);
				$first_record_inside_marc = $marc->next();

				$preview = $va_server["preview"];
				preg_match_all("/\^([0-9][0-9][0-9])\/([a-z])/", $va_server["preview"], $matches);
				$fieldcodes    = $matches[1];
				$subfieldcodes = $matches[2];
				$matches       = $matches[0];

				if ($first_record_inside_marc) {
					foreach ($matches as $key => $match) {
						$fields = $first_record_inside_marc->getFields($fieldcodes[$key]);
						foreach ($fields as $f) {
							$subf = $f->getSubfield($subfieldcodes[$key]);
							if (is_bool($subf)) {
								$preview = str_replace($match, "", $preview);
							} else {
								$preview = str_replace($match, $subf->getData(), $preview);
							}
						}
					}
				}
				$previews[] = $rec_display;

				// Cleanup preview before sending
				$preview = trim($preview, ",");
				$preview = str_replace(" ,", ",", $preview);
				$preview = str_replace(",,", ",", $preview);
				$titles[] = $preview;

				$raws[] = $rec;
			}

			$this->view->setVar("nb_results",      count($files));
			$this->view->setVar("nb_total_hits",   $nb_hits);
			$this->view->setVar("files",           $files);
			$this->view->setVar("previews",        $previews);
			$this->view->setVar("raws",            $raws);
			$this->view->setVar("titles",          $titles);
			$this->view->setVar("import_disabled", (bool)$this->opo_config->get('import_disabled'));
			$this->render('search_results_html.php');
		}

	}

	public function Import() {
		if ((bool)$this->opo_config->get('import_disabled')) {
			$this->view->setVar('message', _t("L'import Z39.50 est désactivé sur cette instance (mapping z3950_import_marc non chargé). La recherche reste disponible."));
			$this->render('error_html.php');
			return;
		}

		$vn_results = $this->request->getParameter('nb_results', pInteger);
		$files = [];
		for ($i = 0; $i < $vn_results; $i++) {
			$filepath = $this->request->getParameter('file_'.$i, pString);
			if ($filepath !== '') { $files[] = $filepath; }
		}
		if (empty($files)) {
			$this->view->setVar('message', _t("Aucune notice sélectionnée."));
			$this->render('error_html.php');
			return;
		}

		$mappings = $this->opo_config->getAssoc('mappings');
		if (is_array($mappings) && !empty($mappings)) {
			return $this->_importViaConfMapping($files, $mappings);
		}
		// Backward compat : ancien comportement via caUtils + mapping XLSX externe
		return $this->_importViaCaUtils($files);
	}

	# -------------------------------------------------------
	# Import — voie historique (caUtils + mapping XLSX `z3950_import_marc`)
	# -------------------------------------------------------
	private function _importViaCaUtils($files) {
		$commands = [];
		$outputs  = [];
		foreach ($files as $filepath) {
			$command = "cd ".__CA_BASE_DIR__."/support && ./bin/caUtils import-data -s ".$filepath." -m z3950_import_marc -f marc -l . -d DEBUG";
			exec($command, $output, $return_var);
			$commands[] = $command;
			$outputs[]  = $output;
		}
		$this->view->setVar('outputs',  $outputs);
		$this->view->setVar('commands', $commands);
		$this->render('import_html.php');
	}

	# -------------------------------------------------------
	# Import — voie conf-driven : parsing UNIMARC en PHP + application
	# des règles déclarées dans `mappings.unimarc` (ou `marc21`) du conf
	# -------------------------------------------------------
	private function _importViaConfMapping($files, $mappings) {
		$vn_locale_id = (int)$this->opo_config->get('locale_id');
		if (!$vn_locale_id) { $vn_locale_id = ca_locales::getDefaultCataloguingLocaleID(); }

		$vs_default_type = (string)$this->opo_config->get('default_type') ?: 'object';
		// Pour le moment, toutes les notices Z39.50 sont demandées en UNIMARC dans
		// Search() (yaz_syntax). Le format MARC21 est prévu mais non câblé à la
		// source — la clé `mappings.marc21` du conf reste utilisable plus tard.
		$vs_format = 'unimarc';

		if (!isset($mappings[$vs_format]) || !is_array($mappings[$vs_format]) || empty($mappings[$vs_format])) {
			$this->view->setVar('message', _t("Aucun mapping configuré pour le format %1 dans `mappings`.", $vs_format));
			$this->render('error_html.php');
			return;
		}
		$rules = $mappings[$vs_format];

		$results = [];
		foreach ($files as $filepath) {
			if (!file_exists($filepath)) {
				$results[] = ['file' => $filepath, 'status' => 'error', 'message' => _t("Fichier introuvable")];
				continue;
			}
			$rec = file_get_contents($filepath);
			$marc = new File_MARC($rec, File_MARC::SOURCE_STRING);
			$marc_record = $marc->next();
			if (!$marc_record) {
				$results[] = ['file' => $filepath, 'status' => 'error', 'message' => _t("Notice MARC illisible")];
				continue;
			}

			$extract = $this->_applyMappingRules($marc_record, $rules);

			// Dédup par idno si le mapping en fournit un
			if (!empty($extract['idno']) && $this->_idnoExists('ca_objects', $extract['idno'])) {
				$results[] = [
					'file'    => $filepath,
					'idno'    => $extract['idno'],
					'title'   => $extract['preferred_label'],
					'status'  => 'skip',
					'message' => _t("Déjà importé (idno %1)", $extract['idno']),
				];
				continue;
			}

			$t_object = new ca_objects();
			$t_object->setMode(ACCESS_WRITE);
			$t_object->set('type_id',   $vs_default_type);
			$t_object->set('locale_id', $vn_locale_id);
			if (!empty($extract['idno'])) { $t_object->set('idno', $extract['idno']); }
			$t_object->insert();
			if ($t_object->numErrors()) {
				$results[] = [
					'file'    => $filepath,
					'idno'    => $extract['idno'],
					'status'  => 'error',
					'message' => _t("Erreur création fiche : %1", join(', ', $t_object->getErrors())),
				];
				continue;
			}

			$title = $extract['preferred_label'] !== '' ? $extract['preferred_label'] : '(sans titre)';
			$t_object->addLabel(['name' => $title], $vn_locale_id, null, true);

			foreach ($extract['attributes'] as $element_code => $value) {
				if (trim($value) !== '') {
					$t_object->addAttribute(
						[$element_code => $value, 'locale_id' => $vn_locale_id],
						$element_code
					);
				}
			}

			$entity_msgs = [];
			foreach ($extract['entity_relations'] as $rel) {
				$entity_id = $this->_findOrCreateEntity($rel['name'], $rel['entity_type'], $rel['auth_idno'], $vn_locale_id);
				if ($entity_id) {
					$ok = $t_object->addRelationship('ca_entities', $entity_id, $rel['rel_type']);
					if ($ok) { $entity_msgs[] = $rel['name']; }
				}
			}

			$t_object->update();
			if ($t_object->numErrors()) {
				$results[] = [
					'file'    => $filepath,
					'idno'    => $extract['idno'],
					'title'   => $title,
					'status'  => 'error',
					'message' => _t("Erreur mise à jour : %1", join(', ', $t_object->getErrors())),
				];
				continue;
			}

			$msg = _t("Fiche créée — id %1", $t_object->getPrimaryKey());
			if (!empty($entity_msgs)) {
				$msg .= ' — ' . _t("entités liées : %1", join(', ', $entity_msgs));
			}
			$results[] = [
				'file'      => $filepath,
				'idno'      => $extract['idno'],
				'title'     => $title,
				'status'    => 'ok',
				'message'   => $msg,
				'object_id' => $t_object->getPrimaryKey(),
			];
		}

		$this->view->setVar('results', $results);
		$this->render('import_html.php');
	}

	# -------------------------------------------------------
	# Helpers : parsing du mapping et extraction MARC
	# -------------------------------------------------------

	/**
	 * Applique les règles de mapping à une notice MARC parsée.
	 * Retourne une structure : [idno, preferred_label, attributes, entity_relations].
	 */
	private function _applyMappingRules($marc_record, $rules) {
		$extract = [
			'idno'             => null,
			'preferred_label'  => '',
			'attributes'       => [],
			'entity_relations' => [],
		];

		foreach ($rules as $source_key => $target_spec) {
			$src = $this->_parseSourceKey($source_key);
			if (!$src) { continue; }
			$tgt = $this->_parseTargetSpec($target_spec);
			if (!$tgt) { continue; }

			$occurrences = $this->_extractMarcOccurrences($marc_record, $src['tag']);
			foreach ($occurrences as $occ) {
				if ($tgt['table'] === 'ca_entities') {
					$name = $this->_buildOccurrenceValue($occ, $src['subcodes'], ' ');
					if ($name === '') { continue; }
					$auth_idno = isset($occ['3']) && $occ['3'] !== '' ? 'ppn:' . $occ['3'] : '';
					$extract['entity_relations'][] = [
						'entity_type' => $tgt['entity_type'] ?? 'ind',
						'rel_type'    => $tgt['rel_type'],
						'name'        => $name,
						'auth_idno'   => $auth_idno,
					];
					continue;
				}
				if ($tgt['table'] !== 'ca_objects') { continue; }

				if ($tgt['field'] === 'preferred_labels') {
					$value = $this->_buildOccurrenceValue($occ, $src['subcodes'], ' ');
					if ($value === '') { continue; }
					if ($tgt['modifier'] === 'append' && $extract['preferred_label'] !== '') {
						$extract['preferred_label'] .= ' ' . $value;
					} else {
						$extract['preferred_label'] = $value;
					}
				} elseif ($tgt['field'] === 'idno') {
					$value = $this->_buildOccurrenceValue($occ, $src['subcodes'], '');
					if ($extract['idno'] === null && $value !== '') {
						$extract['idno'] = $value;
					}
				} else {
					$value = $this->_buildOccurrenceValue($occ, $src['subcodes'], ' ');
					if ($value === '') { continue; }
					$key = $tgt['field'];
					if (!empty($extract['attributes'][$key])) {
						$extract['attributes'][$key] .= '; ' . $value;
					} else {
						$extract['attributes'][$key] = $value;
					}
				}
			}
		}
		return $extract;
	}

	/**
	 * Parse une clé source MARC. Exemples :
	 *  - "001"   → ['tag' => '001', 'subcodes' => []]
	 *  - "200a"  → ['tag' => '200', 'subcodes' => ['a']]
	 *  - "700ab" → ['tag' => '700', 'subcodes' => ['a', 'b']]
	 */
	private function _parseSourceKey($key) {
		$key = trim($key);
		if (!preg_match('/^([0-9]{3})([a-z0-9]*)$/i', $key, $m)) { return null; }
		return [
			'tag'      => $m[1],
			'subcodes' => $m[2] !== '' ? str_split(strtolower($m[2])) : [],
		];
	}

	/**
	 * Parse une spec de cible. Exemples :
	 *  - "ca_objects.preferred_labels"          → ca_objects + preferred_labels
	 *  - "ca_objects.preferred_labels:append"   → idem + modifier 'append'
	 *  - "ca_objects.auteurs"                   → ca_objects + element_code 'auteurs'
	 *  - "ca_entities[ind]%relation:author"     → ca_entities, type ind, rel author
	 *  - "ca_entities%relation:author"          → ca_entities, type ind (défaut)
	 */
	private function _parseTargetSpec($spec) {
		$spec = trim((string)$spec);
		if (preg_match('/^ca_entities(?:\[([a-z0-9_]+)\])?%relation:([a-zA-Z0-9_]+)$/', $spec, $m)) {
			return [
				'table'       => 'ca_entities',
				'entity_type' => $m[1] !== '' ? $m[1] : 'ind',
				'rel_type'    => $m[2],
			];
		}
		if (preg_match('/^ca_objects\.([a-zA-Z0-9_]+)(?::([a-z]+))?$/', $spec, $m)) {
			return [
				'table'    => 'ca_objects',
				'field'    => $m[1],
				'modifier' => $m[2] ?? null,
			];
		}
		return null;
	}

	/**
	 * Pour un tag UNIMARC donné, extrait toutes les occurrences sous forme
	 * d'un tableau de maps (subfield_code → valeur). Pour les controlfields
	 * (sans sous-champs), la valeur est rangée sous la clé vide ''.
	 */
	private function _extractMarcOccurrences($record, $tag) {
		$out = [];
		$fields = $record->getFields($tag);
		if (empty($fields)) { return $out; }
		foreach ($fields as $field) {
			$sub_map = [];
			if ($field instanceof File_MARC_Control_Field) {
				$sub_map[''] = trim($field->getData());
			} else {
				foreach ($field->getSubfields() as $sf) {
					$code = $sf->getCode();
					$val  = trim($sf->getData());
					if ($val === '') { continue; }
					$sub_map[$code] = isset($sub_map[$code]) ? $sub_map[$code] . ' ' . $val : $val;
				}
			}
			if (!empty($sub_map)) { $out[] = $sub_map; }
		}
		return $out;
	}

	/**
	 * Concatène les sous-champs demandés d'une occurrence. Pour un controlfield
	 * (subcodes vide), retourne la valeur unique stockée sous la clé ''.
	 */
	private function _buildOccurrenceValue($occurrence, $subcodes, $separator) {
		if (empty($subcodes)) {
			return $occurrence[''] ?? '';
		}
		$parts = [];
		foreach ($subcodes as $code) {
			if (!empty($occurrence[$code])) { $parts[] = $occurrence[$code]; }
		}
		return implode($separator, $parts);
	}

	# -------------------------------------------------------
	# Helpers : entités liées et déduplication
	# -------------------------------------------------------

	/**
	 * Cherche une `ca_entities` par idno autorité (ex. ppn:029787335) puis par
	 * label préféré exact. Si rien trouvé, crée la fiche avec le type donné.
	 * Retourne l'entity_id ou null en cas d'échec.
	 */
	private function _findOrCreateEntity($name, $entity_type_code, $auth_idno, $locale_id) {
		$name = trim($name);
		if ($name === '') { return null; }

		if ($auth_idno !== '') {
			$db = new Db();
			$res = $db->query("SELECT entity_id FROM ca_entities WHERE idno = ? AND deleted = 0 LIMIT 1", [$auth_idno]);
			if ($res && $res->nextRow()) { return (int)$res->get('entity_id'); }
		}

		$db = new Db();
		$res = $db->query(
			"SELECT e.entity_id FROM ca_entities e
			 JOIN ca_entity_labels l ON e.entity_id = l.entity_id
			 WHERE l.displayname = ? AND l.is_preferred = 1 AND e.deleted = 0
			 LIMIT 1",
			[$name]
		);
		if ($res && $res->nextRow()) { return (int)$res->get('entity_id'); }

		$t = new ca_entities();
		$t->setMode(ACCESS_WRITE);
		$t->set('type_id',   $entity_type_code ?: 'ind');
		$t->set('locale_id', $locale_id);
		if ($auth_idno !== '') { $t->set('idno', $auth_idno); }
		$t->insert();
		if ($t->numErrors()) { return null; }

		$label = ['displayname' => $name];
		if (preg_match('/^([^,]+),\s*(.+)$/', $name, $m)) {
			$label['surname']  = trim($m[1]);
			$label['forename'] = trim($m[2]);
		} else {
			$label['surname'] = $name;
		}
		$t->addLabel($label, $locale_id, null, true);

		return $t->getPrimaryKey() ? (int)$t->getPrimaryKey() : null;
	}

	private function _idnoExists($table, $idno) {
		static $col_map = ['ca_objects' => 'object_id', 'ca_entities' => 'entity_id'];
		if (!isset($col_map[$table]) || $idno === '' || $idno === null) { return false; }
		$col = $col_map[$table];
		$db = new Db();
		$res = $db->query("SELECT $col FROM $table WHERE idno = ? AND deleted = 0 LIMIT 1", [$idno]);
		return ($res && $res->nextRow());
	}
	# -------------------------------------------------------
}
?>
