<?php
/* ----------------------------------------------------------------------
 * mediaImportPlugin.php : 
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
 
	class etatsInrapPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = "Plugin Etats INRAP";
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t("Etats INRAP");
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/etatsInrap.conf');
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true - the statisticsViewerPlugin always initializes ok... (part to complete)
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => true
			);
		}
		# -------------------------------------------------------
		/**
		 * Insert activity menu
		 */
		public function hookRenderMenuBar($pa_menu_bar) {

			if ($o_req = $this->getRequest()) {
                //if(!$o_req->user->hasRole('admin') && !$o_req->user->hasRole('gestion')) { return true;}

				if (isset($pa_menu_bar['suiviInventaireEglises_menu'])) {
					$va_menu_items = $pa_menu_bar['suiviInventaireEglises_menu']['navigation'];
					if (!is_array($va_menu_items)) { $va_menu_items = array(); }
				} else {
					$va_menu_items = array();
				}


                $va_menu_items[1] = array(
                    'displayName' => "Liste des états",
                    'requires' => array(), //array('action:can_use_statistics_viewer_plugin' => 'AND'),
                    "default" => array(
                        'module' => 'etatsInrap',
                        'controller' => 'Generer',
                        'action' => 'Index'
                    )
                );

                $pa_menu_bar['suiviInventaire_menu'] = array(
					'displayName' => _t('Etats'),
					'navigation' => $va_menu_items,
					'requires' => array() //array('action:can_use_statistics_viewer_plugin' => 'AND')
				);
			}

			return $pa_menu_bar;
		}

        /**
         * Insert into ObjectEditor info (side bar)
         */
        public function hookAppendToEditorInspector(array $va_params = array()) {
            $t_item = $va_params["t_item"];

            $vs_table_name = $t_item->tableName();
            $vn_item_id = $t_item->getPrimaryKey();
            $vn_code = $t_item->getTypeCode();

			/*
			if (($vs_table_name == "ca_objects")) {
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele5", array("object"=>$vn_item_id, "etat"=>"constat_etat_simple"));

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;text-decoration:none;'>"
                    . "Constat état simple"
                    . "</a></div>";

                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"] ."<div style='height:2px;'></div>".$vs_buf;
            }

			if (($vs_table_name == "ca_objects")) {
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele5", array("object"=>$vn_item_id, "etat"=>"constat_etat_itinerance"));

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;text-decoration:none;'>"
                    . "Constat état itinérance"
                    . "</a></div>";

                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"] ."<div style='height:2px;'></div>".$vs_buf;
            }
            */

            if (($vs_table_name == "ca_movements") && ($vn_code == "versement")) {
                // === D2026-0103 §4 — évolution 12/08/2026 (téléchargement direct) : début ===
                // Le bouton lançait une page HTML intermédiaire ; "export"=>"docx" fait
                // télécharger directement le bordereau (Modele4 gère déjà ce paramètre).
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele4", array("mouvement"=>$vn_item_id, "etat"=>"bordereau_de_versement", "export"=>"docx"));
                // === D2026-0103 §4 — évolution 12/08/2026 (téléchargement direct) : fin ===

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>"
                    . "Bordereau de versement"
                    . "</a></div>";

                // === D2026-0103 — écran de sélection des contenants : début ===
                // CONCATÉNATION (l'écrasement `=` détruisait les boutons des autres
                // plugins, dont « Sélection des contenants »).
                $va_params["caEditorInspectorAppend"] = ($va_params["caEditorInspectorAppend"] ?? '') . $vs_buf;
                // === D2026-0103 — écran de sélection des contenants : fin ===
            }
            if (($vs_table_name == "ca_movements") && ($vn_code == "movement")) {
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele4b", array("mouvement"=>$vn_item_id, "etat"=>"bordereau_de_mouvement"));

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>"
                    . "Bordereau de mouvement"
                    . "</a></div>";

                // === D2026-0103 — écran de sélection des contenants : début ===
                // CONCATÉNATION (l'écrasement `=` détruisait les boutons des autres
                // plugins, dont « Sélection des contenants »).
                $va_params["caEditorInspectorAppend"] = ($va_params["caEditorInspectorAppend"] ?? '') . $vs_buf;
                // === D2026-0103 — écran de sélection des contenants : fin ===
            }
			if (($vs_table_name == "ca_collections")) {
				$vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele2", array("etat"=>"inventaire_global_de_la_collection", "collection"=>$vn_item_id));

				$vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
					. "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;text-decoration:none;' id='invCollection' >"
					. "Inventaire de la collection"
					. "</a></div>";

				$vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele2b", array("etat"=>"inventaire_des_contenants", "collection"=>$vn_item_id));

				$vs_buf .= "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
					. "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;text-decoration:none;' id='invContenant'>"
					. "Inventaire des contenants"
					. "</a></div>";
					
                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"].$vs_buf;
				//http://inrap.ideesculture.local/gestion/index.php/etatsInrap/Generer/Modele2/etat/inventaire_global_de_la_collection
			}
            if (($vs_table_name == "ca_occurrences") && ($vn_code == "exposition")) {
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele6", array("expo"=>$vn_item_id, "etat"=>"condition_pret_objet_exposition"));

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>"
                    . "Conditions générales de prêt"
                    . "</a></div>";

                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"].$vs_buf;
            }

			if (($vs_table_name == "ca_occurrences") && ($vn_code == "demande_garde_inrap")) {
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele10", array("courrier_id"=>$vn_item_id));

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>"
                    . "Génération du courrier"
                    . "</a></div>";

                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"].$vs_buf;
            }
            
            if (($vs_table_name == "ca_occurrences") && ($vn_code == "courrier_libre")) {
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele11", array("courrier_id"=>$vn_item_id));

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>"
                    . "Génération du courrier"
                    . "</a></div>";

                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"].$vs_buf;
            }

            if (($vs_table_name == "ca_occurrences") && ($vn_code == "demande_versement")) {
                $vs_url = caNavUrl($this->getRequest(), "etatsInrap", "Generer", "Modele12", array("courrier_id"=>$vn_item_id));

                $vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>"
                    . "Génération du courrier"
                    . "</a></div>";

                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"].$vs_buf;
            }

            // >>> LOT10 EN_SUSPENS BEGIN
            // LOT 10 — Courriers types « En suspens » édités DEPUIS LA FICHE OPÉRATION.
            // Décision client du 06/08/2026 : boutons au niveau de la collection, plus aucune
            // occurrence créée en base. Les quatre saisies (destinataire, lieu, date, signataire)
            // sont recueillies dans une fenêtre modale, puis transmises en paramètres à
            // Generer/Modele13 (Lettre 1) ou Generer/Modele14 (Lettre 2), qui n'écrivent rien.
            // Remplace les deux boutons LOT 7 posés sur les occurrences en_suspens_lettre_1/2.
            if (($vs_table_name == "ca_collections") && ($vn_code == "operation")) {
                $va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"]
                    . $this->enSuspensBoutonsEtModale($vn_item_id);
            }
            // <<< LOT10 EN_SUSPENS END

            return $va_params;
        }

		# -------------------------------------------------------
		# >>> LOT10 EN_SUSPENS METHOD BEGIN
		/**
		 * LOT 10 — Boutons « Générer Lettre 1 / Lettre 2 » de la fiche opération et fenêtre modale
		 * de saisie des quatre champs exigés par le client (destinataire, lieu, date, signataire).
		 *
		 * MÉCANISMES RÉUTILISÉS, AUCUN NOUVEAU :
		 *  - modale     : caUI.initPanel() (assets/ca/ca.genericpanel.js), le panneau natif de
		 *                 Providence — voile sombre, centrage, fermeture par Échap ; c'est celui
		 *                 des panneaux « quickadd » et « relation editor » de l'éditeur ;
		 *  - autocomplétion : service de lookup natif lookup/Entity/Get restreint à types=ind
		 *                 (« Personne physique »), branché sur jQuery UI autocomplete — exactement
		 *                 le montage des bundles de relations (themes/default/views/bundles/
		 *                 ca_entities.php) et de ca_objects_deaccession.php ;
		 *  - date       : jQuery UI datepicker, déjà chargé et localisé fr_FR.
		 *
		 * ⚠️ DÉFAUT CORRIGÉ — « la modale ne s'affiche pas » (signalé en recette le 06/08/2026).
		 * Cause mesurée (aucune erreur JavaScript, toutes les bibliothèques présentes) :
		 * caUI.initPanel pose le voile #exposeMask de jQuery Tools en fin de <body> ; tant que le
		 * panneau restait dans le sous-arbre DOM de l'inspecteur, c'est le VOILE qui se retrouvait
		 * au-dessus de lui (document.elementFromPoint au centre du panneau renvoyait « exposeMask »),
		 * donnant une page simplement grisée. Tous les panneaux natifs de Providence sont rendus à
		 * la racine de la page : on s'aligne, le panneau est déplacé sous <body> avant initialisation.
		 *
		 * ROBUSTESSE : les clics des deux boutons sont liés EN PREMIER, et chaque initialisation
		 * optionnelle (panneau, autocomplétions, sélecteur de date) est isolée. Une défaillance de
		 * l'une d'elles dégrade la fonction concernée sans jamais rendre les boutons inertes ; en
		 * l'absence de caUI.initPanel, le panneau est affiché par repli (centré, sans voile).
		 *
		 * @param int $pn_collection_id fiche opération courante
		 * @return string HTML à ajouter à l'inspecteur
		 */
		private function enSuspensBoutonsEtModale($pn_collection_id) {
			$o_req = $this->getRequest();
			$vn_id = (int)$pn_collection_id;

			$vs_url_l1 = caNavUrl($o_req, "etatsInrap", "Generer", "Modele13");
			$vs_url_l2 = caNavUrl($o_req, "etatsInrap", "Generer", "Modele14");
			// Répertoire des personnes physiques : ca_entities de type « ind » (item_id 1665).
			$vs_url_lookup = caNavUrl($o_req, "lookup", "Entity", "Get",
				array("types" => "ind", "noInline" => 1, "quiet" => 1, "limit" => 20));

			$vs_bouton = "background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;text-decoration:none;display:block;";
			$vs_champ  = "width:100%;box-sizing:border-box;padding:5px;border:1px solid #ccc;border-radius:3px;";

			$vs_buf = <<<HTML
<div style="text-align:center;width:100%;margin:10px 0 10px 0;">
	<a href="#" id="caEnSuspensBtnL1" style="{$vs_bouton}">Générer Lettre 1 — Impossibilité de remise de rapport</a>
</div>
<div style="text-align:center;width:100%;margin:10px 0 20px 0;">
	<a href="#" id="caEnSuspensBtnL2" style="{$vs_bouton}">Générer Lettre 2 — Sollicitation CST SRA</a>
</div>

<div id="caEnSuspensPanel" style="display:none;position:fixed;top:0px;left:0px;width:520px;
	 background-color:#FFFFFF;border:2px solid #999999;z-index:31000;padding:0px;text-align:left;">
	<div id="caEnSuspensPanelContent">
		<div class="dialogHeader" id="caEnSuspensTitre">Générer le courrier</div>
		<div style="padding:14px 16px 16px 16px;font-size:12px;">
			<div style="margin-bottom:12px;">
				<label for="caEnSuspensDestinataire" style="font-weight:bold;display:block;margin-bottom:3px;">Destinataire</label>
				<input type="text" id="caEnSuspensDestinataire" autocomplete="off" style="{$vs_champ}"/>
				<input type="hidden" id="caEnSuspensDestinataireId" value=""/>
				<div style="color:#777;font-size:11px;margin-top:2px;">Répertoire des personnes physiques — saisie libre autorisée.</div>
			</div>
			<div style="margin-bottom:12px;">
				<label for="caEnSuspensLieu" style="font-weight:bold;display:block;margin-bottom:3px;">Lieu</label>
				<input type="text" id="caEnSuspensLieu" autocomplete="off" style="{$vs_champ}"/>
			</div>
			<div style="margin-bottom:12px;">
				<label for="caEnSuspensDate" style="font-weight:bold;display:block;margin-bottom:3px;">Date</label>
				<input type="text" id="caEnSuspensDate" autocomplete="off" placeholder="jj/mm/aaaa" style="{$vs_champ}"/>
			</div>
			<div style="margin-bottom:16px;">
				<label for="caEnSuspensSignataire" style="font-weight:bold;display:block;margin-bottom:3px;">Signataire</label>
				<input type="text" id="caEnSuspensSignataire" autocomplete="off" style="{$vs_champ}"/>
				<input type="hidden" id="caEnSuspensSignataireId" value=""/>
				<div style="color:#777;font-size:11px;margin-top:2px;">Répertoire des personnes physiques — la valeur n'est retenue que si elle est choisie dans la liste.</div>
				<div id="caEnSuspensSignataireAlerte" style="display:none;color:#a00;font-size:11px;margin-top:3px;"></div>
			</div>
			<div style="text-align:center;">
				<a href="#" id="caEnSuspensGenerer" style="{$vs_bouton}display:inline-block;padding:8px 24px;">Générer</a>
				<a href="#" id="caEnSuspensAnnuler" style="margin-left:14px;font-size:12px;">Annuler</a>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function() {
	var caEnSuspensCollectionId = {$vn_id};
	var caEnSuspensUrls = { 1: '{$vs_url_l1}', 2: '{$vs_url_l2}' };
	var caEnSuspensTitres = {
		1: 'Générer Lettre 1 — Impossibilité de remise de rapport',
		2: 'Générer Lettre 2 — Sollicitation CST SRA'
	};
	var caEnSuspensLettre = 1;
	var caEnSuspensPanel = null;      // renseigné plus bas si caUI.initPanel est disponible
	var caEnSuspensDateOK = false;    // vrai si le sélecteur de date a pu être posé

	// ---------------------------------------------------------------------
	// Le panneau est déplacé à la racine du <body> AVANT toute initialisation.
	// Posé dans l'inspecteur, il passe SOUS le voile #exposeMask de caUI.initPanel :
	// la page se grise et la modale semble « ne pas s'afficher ». (Défaut de recette
	// du 06/08/2026 — cause mesurée, cf. en-tête de la méthode PHP.)
	// ---------------------------------------------------------------------
	try { jQuery('#caEnSuspensPanel').appendTo('body'); } catch(e) {}

	function caEnSuspensAfficher() {
		if (caEnSuspensPanel) { caEnSuspensPanel.showPanel(null, null, false); return; }
		// repli : caUI.initPanel indisponible — on centre et on affiche à la main.
		var p = jQuery('#caEnSuspensPanel');
		p.show().css({
			top:  Math.max(20, (jQuery(window).height() - p.outerHeight()) / 2) + 'px',
			left: Math.max(20, (jQuery(window).width()  - p.outerWidth())  / 2) + 'px'
		});
	}
	function caEnSuspensFermer() {
		if (caEnSuspensPanel) { caEnSuspensPanel.hidePanel(); return; }
		jQuery('#caEnSuspensPanel').hide();
	}
	function caEnSuspensOuvrir(n) {
		caEnSuspensLettre = n;
		jQuery('#caEnSuspensTitre').text(caEnSuspensTitres[n]);
		jQuery('#caEnSuspensSignataireAlerte').hide();
		// La date est pré-remplie au jour courant, mais reste modifiable : c'est bien la
		// date de la modale, et non la date du jour, qui est reprise par la référence.
		if (!jQuery('#caEnSuspensDate').val()) {
			var d = new Date(), z = function(v){ return (v<10?'0':'')+v; };
			if (caEnSuspensDateOK) {
				try { jQuery('#caEnSuspensDate').datepicker('setDate', d); } catch(e) { caEnSuspensDateOK = false; }
			}
			if (!jQuery('#caEnSuspensDate').val()) {
				jQuery('#caEnSuspensDate').val(z(d.getDate())+'/'+z(d.getMonth()+1)+'/'+d.getFullYear());
			}
		}
		caEnSuspensAfficher();
		return false;
	}

	// =====================================================================
	// 1. LIAISON DES BOUTONS — EN PREMIER, avant toute initialisation
	//    optionnelle : plus aucune d'entre elles ne peut les rendre inertes.
	// =====================================================================
	jQuery('#caEnSuspensBtnL1').click(function() { return caEnSuspensOuvrir(1); });
	jQuery('#caEnSuspensBtnL2').click(function() { return caEnSuspensOuvrir(2); });
	jQuery('#caEnSuspensAnnuler').click(function() { caEnSuspensFermer(); return false; });

	jQuery('#caEnSuspensGenerer').click(function() {
		// Signataire : refus de la saisie libre.
		var sigId = jQuery.trim(jQuery('#caEnSuspensSignataireId').val());
		if (!sigId) {
			if (jQuery.trim(jQuery('#caEnSuspensSignataire').val())) {
				jQuery('#caEnSuspensSignataire').val('');
				jQuery('#caEnSuspensSignataireAlerte')
					.text("Signataire non retenu : il doit être choisi dans la liste du répertoire.")
					.show();
			}
			sigId = '';
		}
		var p = [];
		p.push('collection_id=' + encodeURIComponent(caEnSuspensCollectionId));
		p.push('destinataire='  + encodeURIComponent(jQuery('#caEnSuspensDestinataire').val()));
		p.push('dest_id='       + encodeURIComponent(jQuery('#caEnSuspensDestinataireId').val()));
		p.push('lieu='          + encodeURIComponent(jQuery('#caEnSuspensLieu').val()));
		p.push('date='          + encodeURIComponent(jQuery('#caEnSuspensDate').val()));
		p.push('signataire_id=' + encodeURIComponent(sigId));
		window.open(caEnSuspensUrls[caEnSuspensLettre] + '?' + p.join('&'), '_blank');
		return false;
	});

	// =====================================================================
	// 2. INITIALISATIONS OPTIONNELLES — chacune isolée : un échec dégrade la
	//    fonction concernée, jamais l'ouverture de la modale.
	// =====================================================================

	// 2a. panneau modal natif de Providence
	try {
		if ((typeof caUI !== 'undefined') && (typeof caUI.initPanel === 'function')) {
			caEnSuspensPanel = caUI.initPanel({
				panelID: 'caEnSuspensPanel',
				panelContentID: 'caEnSuspensPanelContent',
				center: true,
				exposeBackgroundOpacity: 0.6,
				clearOnClose: false
			});
		}
	} catch(e) { caEnSuspensPanel = null; }

	// 2b. autocomplétion sur le répertoire des personnes physiques (types=ind)
	function caEnSuspensAutocomplete(champ, hidden, apresSelection) {
		if (!jQuery.fn.autocomplete) { return false; }
		jQuery(champ).autocomplete({
			minLength: 3, delay: 500, html: true,
			source: function(request, response) {
				jQuery.ajax({ url: '{$vs_url_lookup}', dataType: 'json',
					data: { term: request.term },
					success: function(data) { response(data); },
					error: function() { response([]); } });
			},
			select: function(event, ui) {
				var label = jQuery.trim(ui.item.label.replace(/<\/?[^>]+>/gi, ''));
				jQuery(champ).val(label);
				jQuery(hidden).val(ui.item.id);
				if (apresSelection) { apresSelection(); }
				event.preventDefault();
			}
		});
		// toute frappe au clavier invalide l'identifiant : il ne subsiste que
		// tant que la valeur affichée est exactement celle choisie dans la liste.
		jQuery(champ).on('input', function() { jQuery(hidden).val(''); });
		return true;
	}
	// Destinataire : la saisie libre est AUTORISÉE — on ne touche pas au texte tapé,
	// l'identifiant n'est mémorisé que pour en déduire la civilité (formule d'appel).
	try { caEnSuspensAutocomplete('#caEnSuspensDestinataire', '#caEnSuspensDestinataireId', null); } catch(e) {}
	// Signataire : saisie libre INTERDITE. L'identifiant n'est posé que par « select » ;
	// à la génération, s'il est vide, la valeur tapée n'est PAS retenue.
	try {
		caEnSuspensAutocomplete('#caEnSuspensSignataire', '#caEnSuspensSignataireId',
			function() { jQuery('#caEnSuspensSignataireAlerte').hide(); });
	} catch(e) {}

	// 2c. sélecteur de date (jQuery UI, localisé fr_FR).
	//     Absent, le champ reste un champ texte utilisable au format jj/mm/aaaa.
	try {
		if (jQuery.fn.datepicker) {
			jQuery('#caEnSuspensDate').datepicker({ dateFormat: 'dd/mm/yy', constrainInput: false });
			caEnSuspensDateOK = true;
		}
	} catch(e) { caEnSuspensDateOK = false; }
});
</script>
HTML;
			return $vs_buf;
		}
		# <<< LOT10 EN_SUSPENS METHOD END

		public function hookAddDomainSecurityPolicy($va_params = array()) {
			$va_params[] = "cdnjs.cloudflare.com";
			return $va_params;
		}

        # -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			return array();
		}
		
	}
	