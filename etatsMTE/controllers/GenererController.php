<?php
/* ----------------------------------------------------------------------
 * plugins/etatsMTE/controllers/GenererController.php
 * ----------------------------------------------------------------------
 * Plugin d'etats pour le MTE (Mobiliers Classés)
 * Adapté depuis etatObs (Observatoire) pour le contexte MTE
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');

error_reporting(E_ERROR);

class GenererController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;
	protected $ops_plugin_name;
	protected $ops_plugin_path;
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->ops_plugin_name = "etatsMTE";
		$this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;

		$vs_conf_file = $this->ops_plugin_path."/conf/".$this->ops_plugin_name.".conf";
		if(is_file($vs_conf_file)) {
			$this->opo_config = Configuration::load($vs_conf_file);
		}
	}

	# -------------------------------------------------------
	# Index — page d'accueil du plugin
	# -------------------------------------------------------
	public function Index($type="") {
		$this->render('index_html.php');
	}

	# -------------------------------------------------------
	# ConstatDetat — affichage HTML du constat d'état d'un objet
	# -------------------------------------------------------
	public function ConstatDetat() {
		$vn_object_id = $this->getRequest()->getParameter("objet", pInteger);
		if(!$vn_object_id) {
			$this->view->setVar("message", "Cet état nécessite de sélectionner une fiche objet.");
			return $this->render("error_html.php");
		}

		$titre = "Constat d'état";
		$headers = [
			"N° Inv.",
			"Titre de l'oeuvre",
			"Auteur",
			"Date",
			"Matière / Technique",
			"Dimensions",
			"Poids",
			"Localisation",
			"Déposant",
		];

		$va_results = [];
		$va_results[0] = $headers;

		$obj = new ca_objects($vn_object_id);

		$dim = "";
		$poids = "";
		$dims = explode(";", $obj->getWithTemplate("<unit relativeTo='ca_objects.dimensions' delimiter=';'>^ca_objects.dimensions.type_dimensions_list : ^ca_objects.dimensions.dim_val ^ca_objects.dimensions.dim_unit<ifdef code='ca_objects.dimensions.dim_precisions'> ^ca_objects.dimensions.dim_precisions</ifdef></unit>"));
		for ($j = 0; $j < sizeOf($dims); $j++) {
			if(strpos($dims[$j], "Poids") !== false) {
				$poids .= $dims[$j].", ";
			} else {
				$dim .= $dims[$j].", ";
			}
		}
		$dim = mb_substr($dim, 0, -2);
		$poids = mb_substr($poids, 0, -2);

		$va_results[1] = [
			$obj->getWithTemplate("^ca_objects.idno"),
			$obj->getWithTemplate("^ca_objects.preferred_labels"),
			$obj->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='creation_auteur'>^ca_entities.preferred_labels.displayname</unit>"),
			$obj->getWithTemplate("^ca_objects.millesime_creation"),
			$obj->getWithTemplate("^ca_objects.materiaux_techniques"),
			$dim,
			$poids,
			$obj->getWithTemplate("^ca_objects.site"),
			$obj->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='depositaire'>^ca_entities.preferred_labels.displayname</unit>"),
		];

		$this->view->setVar('results', $va_results);
		$this->view->setVar('titre', $titre);
		$this->view->setVar('objet', $vn_object_id);

		$this->render('modele1_html.php');
	}

	# -------------------------------------------------------
	# ConstatDetatDoc — génération DOCX du constat d'état
	# -------------------------------------------------------
	public function ConstatDetatDoc() {
		$vn_object_id = $this->getRequest()->getParameter("objet", pInteger);

		if(!$vn_object_id) {
			$this->view->setVar("message", "Cet état nécessite de sélectionner une fiche objet.");
			return $this->render("error_html.php");
		}

		$obj = new ca_objects($vn_object_id);
		$dim = "";
		$poids = "";
		$dims = explode(";", $obj->getWithTemplate("<unit relativeTo='ca_objects.dimensions' delimiter=';'>^ca_objects.dimensions.type_dimensions_list : ^ca_objects.dimensions.dim_val ^ca_objects.dimensions.dim_unit<ifdef code='ca_objects.dimensions.dim_precisions'> ^ca_objects.dimensions.dim_precisions</ifdef></unit>"));
		for ($j = 0; $j < sizeOf($dims); $j++) {
			if(strpos($dims[$j], "Poids") !== false) {
				$poids .= substr($dims[$j], 7).", ";
			} else {
				$dim .= $dims[$j].", ";
			}
		}
		$dim = mb_substr($dim, 0, -2);
		$poids = mb_substr($poids, 0, -2);

		$va_results = [
			$obj->getWithTemplate("^ca_objects.idno"),
			$obj->getWithTemplate("^ca_objects.preferred_labels"),
			$obj->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='creation_auteur'>^ca_entities.preferred_labels.displayname</unit>"),
			$obj->getWithTemplate("^ca_objects.millesime_creation"),
			$obj->getWithTemplate("^ca_objects.materiaux_techniques"),
			$dim,
			$poids,
			$obj->getWithTemplate("^ca_objects.site"),
			$obj->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='depositaire'>^ca_entities.preferred_labels.displayname</unit>"),
		];

		$this->view->setVar('results', $va_results);
		$result = $this->render('modele1_docx.php');
		print $result;
		die();
	}
}

?>
