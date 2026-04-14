<?php
/* ----------------------------------------------------------------------
 * SimplePubmedController.php
 * ----------------------------------------------------------------------
 * CollectiveAccess — Centre Antipoisons
 *
 * Contrôleur du plugin d'import PubMed.
 * Utilise l'API NCBI E-utilities pour récupérer les notices PubMed
 * et les importe directement dans CollectiveAccess via l'API PHP.
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__ . '/Configuration.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');

class SimplePubmedController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;

	/** URL de base de l'API NCBI E-utilities */
	const EFETCH_URL  = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi';
	const ESEARCH_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi';

	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths = null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
		$this->opo_config = Configuration::load(__CA_APP_DIR__ . '/plugins/SimplePubmed/conf/SimplePubmed.conf');
	}

	# -------------------------------------------------------
	# Index — Formulaire de recherche
	# -------------------------------------------------------
	public function Index() {
		$this->render('index_html.php');
	}

	# -------------------------------------------------------
	# Search — Interrogation de l'API PubMed
	# -------------------------------------------------------
	public function Search() {
		$ps_mode   = $this->request->getParameter('mode',   pString); // 'pmid' ou 'text'
		$ps_search = trim($this->request->getParameter('search', pString));

		if (empty($ps_search)) {
			$this->view->setVar('error', 'Veuillez saisir un identifiant PMID ou des termes de recherche.');
			$this->render('error_html.php');
			return;
		}

		$pmids = array();

		if ($ps_mode === 'pmid') {
			// Nettoyage : on accepte des PMIDs séparés par des virgules, espaces ou sauts de ligne
			$raw = preg_split('/[\s,;]+/', $ps_search, -1, PREG_SPLIT_NO_EMPTY);
			foreach ($raw as $r) {
				if (is_numeric($r)) { $pmids[] = (int)$r; }
			}
			if (empty($pmids)) {
				$this->view->setVar('error', 'Aucun PMID valide trouvé. Un PMID est un nombre entier (ex. 12345678).');
				$this->render('error_html.php');
				return;
			}
		} else {
			// Recherche textuelle : esearch puis récupération des PMIDs
			$pmids = $this->_esearch($ps_search);
			if ($pmids === false) {
				$this->view->setVar('error', 'Erreur lors de la communication avec l\'API PubMed. Vérifiez votre connexion Internet.');
				$this->render('error_html.php');
				return;
			}
			if (empty($pmids)) {
				$this->view->setVar('error', 'Aucun résultat trouvé pour : ' . htmlspecialchars($ps_search));
				$this->render('error_html.php');
				return;
			}
		}

		// Récupération des notices XML via efetch
		$articles = $this->_efetch($pmids);
		if ($articles === false) {
			$this->view->setVar('error', 'Impossible de récupérer les notices depuis PubMed.');
			$this->render('error_html.php');
			return;
		}

		// Vérification des doublons déjà présents dans CA
		$existing_pmids = $this->_getExistingPmids($pmids);

		$this->view->setVar('articles',       $articles);
		$this->view->setVar('existing_pmids', $existing_pmids);
		$this->render('search_results_html.php');
	}

	# -------------------------------------------------------
	# Import — Importation des notices sélectionnées
	# -------------------------------------------------------
	public function Import() {
		$ps_pmids_json = $this->request->getParameter('articles_json', pString);
		$va_articles   = json_decode($ps_pmids_json, true);

		if (empty($va_articles) || !is_array($va_articles)) {
			$this->view->setVar('error', 'Aucune notice sélectionnée pour l\'import.');
			$this->render('error_html.php');
			return;
		}

		$vn_locale_id    = (int)$this->opo_config->get('locale_id');
		$vs_default_type = $this->opo_config->get('default_type');
		$va_type_mapping = $this->opo_config->getAssoc('type_mapping');

		$results = array();

		foreach ($va_articles as $article) {
			$pmid  = isset($article['pmid'])  ? (string)$article['pmid']  : '';
			$title = isset($article['title']) ? (string)$article['title'] : '(sans titre)';

			if (empty($pmid)) { continue; }

			// Vérification doublon
			if ($this->_pmidExists($pmid)) {
				$results[] = array(
					'pmid'    => $pmid,
					'title'   => $title,
					'status'  => 'skip',
					'message' => 'Déjà présent dans la base (PMID ' . $pmid . ')'
				);
				continue;
			}

			// Détermination du type d'objet
			$vs_type = $vs_default_type;
			if (!empty($article['pub_types']) && is_array($article['pub_types'])) {
				foreach ($article['pub_types'] as $pt) {
					if (isset($va_type_mapping[$pt])) {
						$vs_type = $va_type_mapping[$pt];
						break;
					}
				}
			}

			// Création de la fiche ca_objects
			$t_object = new ca_objects();
			$t_object->setMode(ACCESS_WRITE);
			$t_object->set('type_id',  $vs_type);
			$t_object->set('locale_id', $vn_locale_id);
			$t_object->insert();

			if ($t_object->numErrors()) {
				$results[] = array(
					'pmid'    => $pmid,
					'title'   => $title,
					'status'  => 'error',
					'message' => 'Erreur création fiche : ' . join(', ', $t_object->getErrors())
				);
				continue;
			}

			// Label préféré (titre)
			$t_object->addLabel(
				array('name' => $title),
				$vn_locale_id,
				null,
				true
			);

			// Attributs bibliographiques
			$attr_map = array(
				'bibl_pmid'         => 'pmid',
				'bibl_doi'          => 'doi',
				'bibl_authors'      => 'authors',
				'bibl_abstract'     => 'abstract',
				'bibl_journal'      => 'journal',
				'bibl_volume'       => 'volume',
				'bibl_issue'        => 'issue',
				'bibl_pages'        => 'pages',
				'bibl_issn'         => 'issn',
				'bibl_publisher'    => 'publisher',
				'bibl_affiliation'  => 'affiliation',
				'bibl_nlmuid'       => 'nlmuid',
				'bibl_country'      => 'country',
				'bibl_mesh'         => 'mesh',
				'bibl_mesh_major'   => 'mesh_major',
				'bibl_mesh_substance' => 'mesh_substance',
				'bibl_copy_uri'     => 'pubmed_url',
			);

			foreach ($attr_map as $element_code => $article_key) {
				$value = isset($article[$article_key]) ? trim($article[$article_key]) : '';
				if ($value !== '') {
					$t_object->addAttribute(
						array($element_code => $value, 'locale_id' => $vn_locale_id),
						$element_code
					);
				}
			}

			// Année de publication (DateRange)
			if (!empty($article['year'])) {
				$t_object->addAttribute(
					array('bibl_year' => $article['year'], 'locale_id' => $vn_locale_id),
					'bibl_year'
				);
			}

			// Langue (attribut de type liste)
			if (!empty($article['language_item_id'])) {
				$t_object->addAttribute(
					array('bibl_language' => (int)$article['language_item_id'], 'locale_id' => $vn_locale_id),
					'bibl_language'
				);
			}

			// Source d'indexation (toujours PubMed/MEDLINE pour ce plugin)
			$t_object->addAttribute(
				array('bibl_indexed_by' => 'PubMed/MEDLINE', 'locale_id' => $vn_locale_id),
				'bibl_indexed_by'
			);

			$t_object->update();

			if ($t_object->numErrors()) {
				$results[] = array(
					'pmid'    => $pmid,
					'title'   => $title,
					'status'  => 'error',
					'message' => 'Erreur mise à jour attributs : ' . join(', ', $t_object->getErrors())
				);
			} else {
				$results[] = array(
					'pmid'      => $pmid,
					'title'     => $title,
					'status'    => 'ok',
					'message'   => 'Importé avec succès — Cote : ' . $t_object->get('idno'),
					'object_id' => $t_object->getPrimaryKey(),
				);
			}
		}

		$this->view->setVar('results', $results);
		$this->render('import_html.php');
	}

	# -------------------------------------------------------
	# Méthodes privées — appels API et utilitaires
	# -------------------------------------------------------

	/**
	 * Recherche textuelle PubMed via esearch.
	 * Retourne un tableau de PMIDs (entiers) ou false en cas d'erreur.
	 */
	private function _esearch($query) {
		$max    = (int)$this->opo_config->get('max_results') ?: 20;
		$apikey = (string)$this->opo_config->get('ncbi_api_key');

		$params = array(
			'db'      => 'pubmed',
			'term'    => $query,
			'retmax'  => $max,
			'retmode' => 'json',
		);
		if ($apikey) { $params['api_key'] = $apikey; }

		$url  = self::ESEARCH_URL . '?' . http_build_query($params);
		$data = $this->_httpGet($url);
		if ($data === false) { return false; }

		$json = json_decode($data, true);
		if (!isset($json['esearchresult']['idlist'])) { return false; }

		return array_map('intval', $json['esearchresult']['idlist']);
	}

	/**
	 * Récupération des notices XML via efetch.
	 * Retourne un tableau de notices parsées ou false en cas d'erreur.
	 */
	private function _efetch(array $pmids) {
		if (empty($pmids)) { return array(); }

		$apikey = (string)$this->opo_config->get('ncbi_api_key');

		$params = array(
			'db'      => 'pubmed',
			'id'      => implode(',', $pmids),
			'retmode' => 'xml',
			'rettype' => 'abstract',
		);
		if ($apikey) { $params['api_key'] = $apikey; }

		$url  = self::EFETCH_URL . '?' . http_build_query($params);
		$data = $this->_httpGet($url);
		if ($data === false) { return false; }

		return $this->_parsePubmedXml($data);
	}

	/**
	 * Parse le XML PubMed (PubmedArticleSet) et retourne un tableau de notices.
	 */
	private function _parsePubmedXml($xml_string) {
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($xml_string);
		if ($xml === false) { return false; }

		$articles = array();

		foreach ($xml->PubmedArticle as $pub) {
			$mc  = $pub->MedlineCitation;
			$art = $mc->Article;

			$pmid  = (string)$mc->PMID;
			$title = (string)$art->ArticleTitle;
			// Nettoyage du titre (peut contenir des balises XML)
			$title = strip_tags($title);

			// Auteurs
			$authors_list = array();
			if (isset($art->AuthorList->Author)) {
				foreach ($art->AuthorList->Author as $author) {
					$ln = (string)$author->LastName;
					$fn = (string)$author->ForeName;
					if ($ln) {
						$authors_list[] = $fn ? "$ln $fn" : $ln;
					} elseif (isset($author->CollectiveName)) {
						$authors_list[] = (string)$author->CollectiveName;
					}
				}
			}
			$authors = implode(', ', $authors_list);

			// Résumé
			$abstract_parts = array();
			if (isset($art->Abstract->AbstractText)) {
				foreach ($art->Abstract->AbstractText as $ab) {
					$label = (string)$ab->attributes()['Label'];
					$text  = (string)$ab;
					$abstract_parts[] = $label ? "$label: $text" : $text;
				}
			}
			$abstract = implode("\n", $abstract_parts);

			// Journal
			$journal = '';
			$issn    = '';
			$volume  = '';
			$issue   = '';
			$pages   = '';
			$year    = '';
			if (isset($art->Journal)) {
				$journal = (string)$art->Journal->Title;
				if (isset($art->Journal->ISSN)) {
					$issn = (string)$art->Journal->ISSN;
				}
				if (isset($art->Journal->JournalIssue)) {
					$ji     = $art->Journal->JournalIssue;
					$volume = (string)$ji->Volume;
					$issue  = (string)$ji->Issue;
					// Année
					if (isset($ji->PubDate->Year)) {
						$year = (string)$ji->PubDate->Year;
					} elseif (isset($ji->PubDate->MedlineDate)) {
						// Format "2024 Jan-Feb" → on prend les 4 premiers chiffres
						preg_match('/\d{4}/', (string)$ji->PubDate->MedlineDate, $m);
						$year = $m[0] ?? '';
					}
				}
			}

			// Pages
			if (isset($art->Pagination->MedlinePgn)) {
				$pages = (string)$art->Pagination->MedlinePgn;
			}

			// DOI
			$doi = '';
			if (isset($art->ELocationID)) {
				foreach ($art->ELocationID as $loc) {
					if ((string)$loc->attributes()['EIdType'] === 'doi') {
						$doi = (string)$loc;
						break;
					}
				}
			}

			// NLM UID
			$nlmuid = '';
			if (isset($mc->MedlineJournalInfo->NlmUniqueID)) {
				$nlmuid = (string)$mc->MedlineJournalInfo->NlmUniqueID;
			}

			// Pays
			$country = '';
			if (isset($mc->MedlineJournalInfo->Country)) {
				$country = (string)$mc->MedlineJournalInfo->Country;
			}

			// Affiliation (première affiliation trouvée)
			$affiliation = '';
			if (isset($art->AuthorList->Author)) {
				foreach ($art->AuthorList->Author as $author) {
					if (isset($author->AffiliationInfo->Affiliation)) {
						$affiliation = (string)$author->AffiliationInfo->Affiliation;
						break;
					}
				}
			}

			// MeSH termes
			$mesh_terms       = array();
			$mesh_major_terms = array();
			if (isset($mc->MeshHeadingList->MeshHeading)) {
				foreach ($mc->MeshHeadingList->MeshHeading as $mh) {
					$descriptor = (string)$mh->DescriptorName;
					$is_major   = ((string)$mh->DescriptorName->attributes()['MajorTopicYN']) === 'Y';
					$mesh_terms[] = $descriptor;
					if ($is_major) {
						$mesh_major_terms[] = $descriptor;
					}
					// Qualificatifs
					if (isset($mh->QualifierName)) {
						foreach ($mh->QualifierName as $qn) {
							$mesh_terms[] = "$descriptor/" . (string)$qn;
							if ((string)$qn->attributes()['MajorTopicYN'] === 'Y') {
								$mesh_major_terms[] = "$descriptor/" . (string)$qn;
							}
						}
					}
				}
			}
			$mesh          = implode('; ', array_unique($mesh_terms));
			$mesh_major    = implode('; ', array_unique($mesh_major_terms));

			// Substances
			$substances = array();
			if (isset($mc->ChemicalList->Chemical)) {
				foreach ($mc->ChemicalList->Chemical as $chem) {
					$substances[] = (string)$chem->NameOfSubstance;
				}
			}
			$mesh_substance = implode('; ', $substances);

			// Types de publication
			$pub_types = array();
			if (isset($art->PublicationTypeList->PublicationType)) {
				foreach ($art->PublicationTypeList->PublicationType as $pt) {
					$pub_types[] = (string)$pt;
				}
			}

			// Langue (code ISO 639-2 à 3 lettres → item_id CA)
			$language_item_id = null;
			if (isset($art->Language)) {
				$lang_code = strtolower(trim((string)$art->Language));
				$lang_map  = array(
					'eng' => 250, // Anglais
					'fre' => 251, 'fra' => 251, // Français
					'dut' => 252, 'nld' => 252, // Néerlandais
					'ger' => 253, 'deu' => 253, // Allemand
					'spa' => 254,               // Espagnol
					'ita' => 255,               // Italien
					'por' => 256,               // Portugais
					'jpn' => 257,               // Japonais
					'chi' => 258, 'zho' => 258, // Chinois
				);
				$language_item_id = isset($lang_map[$lang_code]) ? $lang_map[$lang_code] : 259; // 259 = Autre
			}

			// URL PubMed
			$pubmed_url = 'https://pubmed.ncbi.nlm.nih.gov/' . $pmid . '/';

			$articles[] = array(
				'pmid'             => $pmid,
				'title'            => $title,
				'authors'          => $authors,
				'abstract'         => $abstract,
				'journal'          => $journal,
				'issn'             => $issn,
				'volume'           => $volume,
				'issue'            => $issue,
				'pages'            => $pages,
				'year'             => $year,
				'doi'              => $doi,
				'nlmuid'           => $nlmuid,
				'country'          => $country,
				'affiliation'      => $affiliation,
				'mesh'             => $mesh,
				'mesh_major'       => $mesh_major,
				'mesh_substance'   => $mesh_substance,
				'pub_types'        => $pub_types,
				'pubmed_url'       => $pubmed_url,
				'language_item_id' => $language_item_id,
			);
		}

		return $articles;
	}

	/**
	 * Requête HTTP GET simple (cURL si disponible, sinon file_get_contents).
	 * Retourne le corps de la réponse ou false en cas d'erreur.
	 */
	private function _httpGet($url) {
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_USERAGENT, 'CollectiveAccess/SimplePubmedPlugin');
			$data = curl_exec($ch);
			$err  = curl_error($ch);
			curl_close($ch);
			return ($err || $data === false) ? false : $data;
		}
		// Fallback file_get_contents
		$ctx  = stream_context_create(array(
			'http' => array('timeout' => 30, 'user_agent' => 'CollectiveAccess/SimplePubmedPlugin')
		));
		$data = @file_get_contents($url, false, $ctx);
		return ($data === false) ? false : $data;
	}

	/**
	 * Retourne la liste des PMIDs déjà présents dans ca_objects.
	 */
	private function _getExistingPmids(array $pmids) {
		$existing = array();
		foreach ($pmids as $pmid) {
			if ($this->_pmidExists((string)$pmid)) {
				$existing[] = (string)$pmid;
			}
		}
		return $existing;
	}

	/**
	 * Vérifie si un PMID existe déjà dans ca_objects via l'attribut bibl_pmid.
	 */
	private function _pmidExists($pmid) {
		$t = new ca_objects();
		// ca_attributes est la table centrale (row_id = object_id, table_num = 57 pour ca_objects)
		$res = $t->getDb()->query(
			"SELECT a.row_id
			   FROM ca_attributes a
			   JOIN ca_attribute_values av ON a.attribute_id = av.attribute_id
			   JOIN ca_metadata_elements me ON av.element_id = me.element_id
			   JOIN ca_objects o ON a.row_id = o.object_id
			  WHERE a.table_num = 57
			    AND me.element_code = 'bibl_pmid'
			    AND av.value_longtext1 = ?
			    AND o.deleted = 0
			  LIMIT 1",
			array((string)$pmid)
		);
		return ($res && $res->nextRow());
	}
}
?>
