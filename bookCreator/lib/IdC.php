<?php
/* ----------------------------------------------------------------------
 * IdC — helper de traduction partagé des plugins IdéesCulture pour CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html).
 * ----------------------------------------------------------------------
 *
 * POURQUOI CE FICHIER
 *
 * CollectiveAccess ne prévoit aucun emplacement de traduction dans un plugin.
 * Les trois mécanismes existants consultent des listes de chemins codées en dur,
 * dont aucune ne descend dans app/plugins :
 *
 *   - catalogues gettext : app/helpers/initializeLocale.php, validateLocale()
 *     -> themes/<theme>/locale, themes/default/locale, app/locale/user, app/locale
 *   - surcharge translations.conf : Configuration::load()
 *     -> app/conf/local, themes/<theme>/conf, app/conf
 *   - extraction des chaînes : caUtils extract-strings-for-translation
 *     -> themes, app/models, app/lib, app/helpers, app/conf
 *
 * La voie documentée jusqu'ici (fusionner conf/translations.conf.dist dans
 * app/conf/local/translations.conf) a deux défauts :
 *
 *   1. elle impose une étape d'installation manuelle par instance, et place les
 *      chaînes du plugin dans un espace de noms plat partagé avec tous les
 *      autres plugins ;
 *   2. surtout, _t() (app/helpers/utilityHelpers.php:74) retourne immédiatement
 *      depuis la branche $g_translation_strings, AVANT le bloc d'interpolation
 *      des %n situé en fin de fonction. Toute chaîne surchargée contenant un %n
 *      s'affiche donc avec ses marqueurs littéraux :
 *
 *        via gettext   : _t('Deleted %1 records', 7) -> 'Deleted 7 records'
 *        via surcharge : _t('Deleted %1 records', 7) -> '%1 fiches supprimées'
 *
 *      Sur le catalogue de bookCreator, 24 appels sur 160 sont concernés.
 *
 * IdC fait sa propre interpolation : le défaut du cœur devient sans effet, et
 * aucun correctif de CollectiveAccess n'est nécessaire.
 *
 * DÉPLOIEMENT
 *
 * Chaque plugin IdéesCulture embarque une copie de ce fichier dans son lib/ et
 * le charge derrière un class_exists() : le premier plugin chargé enregistre la
 * classe, les suivants réutilisent celle en place. On ne peut pas la placer dans
 * un répertoire partagé : les plugins sont déployés individuellement dans
 * app/plugins (par lien symbolique ou par copie), sans racine commune garantie.
 *
 * L'API est volontairement minuscule et doit rester strictement additive : en
 * cas de versions différentes entre deux plugins, c'est la première chargée qui
 * gagne. Consulter IdC::VERSION avant d'utiliser une nouveauté.
 * ----------------------------------------------------------------------
 */

if (!class_exists('IdC', false)) {

	class IdC {
		/**
		 * Incrémenter à chaque ajout à l'API. Strictement additif : un plugin
		 * récent doit continuer de fonctionner avec une version antérieure
		 * chargée par un autre plugin, quitte à se priver d'une nouveauté.
		 */
		const VERSION = 1;

		/** [domaine => [clé => valeur|[locale => valeur]]] */
		private static $strings = [];

		/**
		 * Langue de repli. Les plugins IdéesCulture sont développés en français
		 * pour un public francophone : c'est la langue servie quand la locale
		 * courante n'est pas connue (ligne de commande, amorçage précoce).
		 */
		private static $fallback_locale = 'fr_FR';

		# -------------------------------------------------------
		/**
		 * Enregistre le catalogue d'un plugin. Les domaines sont cloisonnés :
		 * deux plugins peuvent employer la même clé sans se marcher dessus.
		 *
		 * @param string $domain Nom du plugin (ex. 'bookCreator')
		 * @param array  $strings [clé => valeur] ou [clé => [locale => valeur]]
		 */
		public static function register(string $domain, ?array $strings) : void {
			if (!$strings) { return; }
			self::$strings[$domain] = isset(self::$strings[$domain])
				? array_merge(self::$strings[$domain], $strings)
				: $strings;
		}

		# -------------------------------------------------------
		/**
		 * Charge et enregistre un catalogue au format de configuration
		 * CollectiveAccess (bloc `strings`), tel que conf/translations.conf.
		 *
		 * @return bool false si le fichier est absent ou illisible
		 */
		public static function registerFile(string $domain, string $path) : bool {
			if (!@is_readable($path)) { return false; }

			// ATTENTION — piège de Configuration::load() : la résolution se fait
			// par NOM DE FICHIER, et la fusion avec app/conf/<même nom> est faite
			// INCONDITIONNELLEMENT (app/lib/Configuration.php, ligne ~158 : ce
			// bloc est hors de la garde $dont_load_from_default_path). Le fichier
			// du coeur gagne.
			//
			// Concrètement, un catalogue de plugin nommé translations.conf est
			// fusionné avec app/conf/translations.conf, dont le bloc strings est
			// vide : on obtient 0 chaîne, sans la moindre erreur. Le drapeau ne
			// protège pas de ce cas, seul le nom de fichier le fait.
			//
			// => Tout fichier de configuration livré par un plugin IdéesCulture
			//    doit porter un nom absent de app/conf/ (ici préfixé par le nom
			//    du plugin). Les drapeaux ci-dessous restent posés par principe,
			//    pour ne pas hériter d'une surcharge de thème ou d'instance.
			$conf = Configuration::load($path, false, false, true, true);
			$strings = $conf ? $conf->get('strings') : null;
			if (!is_array($strings) || !sizeof($strings)) { return false; }
			self::register($domain, $strings);
			return true;
		}

		# -------------------------------------------------------
		/**
		 * Locale de l'interface. $_locale (Zend_Locale) est la source fiable :
		 * $g_ui_locale vaut NULL tant qu'aucun cookie de langue n'a été posé.
		 */
		public static function locale() : string {
			global $_locale, $g_ui_locale;
			if (isset($_locale) && strlen($l = (string)$_locale)) { return $l; }
			if (!empty($g_ui_locale)) { return (string)$g_ui_locale; }
			return self::$fallback_locale;
		}

		# -------------------------------------------------------
		public static function setFallbackLocale(string $locale) : void {
			self::$fallback_locale = $locale;
		}

		# -------------------------------------------------------
		/**
		 * Traduit une clé et interpole les marqueurs %1, %2… passés en arguments
		 * supplémentaires, à la manière de _t().
		 *
		 * Ordre de résolution :
		 *   1. catalogues enregistrés, pour la locale courante ;
		 *   2. catalogues enregistrés, pour la langue seule (fr_FR -> fr_*) ;
		 *   3. _t() de CollectiveAccess, pour que les chaînes du cœur
		 *      (« Enregistrer », « Supprimer »…) restent traduites sans les
		 *      redéclarer ici ;
		 *   4. la clé elle-même.
		 *
		 * L'appel à _t() ne passe qu'un argument : l'interpolation est faite
		 * ici, jamais par le cœur, ce qui neutralise le défaut décrit en tête.
		 */
		public static function _t(string $key, ...$args) : string {
			if ($key === '') { return ''; }

			$locale = self::locale();
			$str = null;

			foreach (self::$strings as $entries) {
				if (!array_key_exists($key, $entries)) { continue; }
				$val = $entries[$key];

				if (is_string($val)) {            // même valeur pour toutes les locales
					$str = $val;
					break;
				}
				if (is_array($val)) {
					if (isset($val[$locale])) { $str = $val[$locale]; break; }
					// repli sur la même langue dans une autre variante régionale
					$lang = substr($locale, 0, 2);
					foreach ($val as $l => $v) {
						if (substr((string)$l, 0, 2) === $lang) { $str = $v; break 2; }
					}
				}
			}

			if ($str === null) {
				// Pas dans nos catalogues : on laisse sa chance au cœur, sans
				// lui passer d'arguments (voir plus haut).
				$str = function_exists('_t') ? _t($key) : $key;
			}

			return self::interpolate($str, $args);
		}

		# -------------------------------------------------------
		/** Comme _t(), mais affiche au lieu de retourner (équivalent de _p()). */
		public static function _p(string $key, ...$args) : void {
			print self::_t($key, ...$args);
		}

		# -------------------------------------------------------
		/** Un domaine a-t-il déjà été enregistré ? */
		public static function registered(string $domain) : bool {
			return isset(self::$strings[$domain]);
		}

		# -------------------------------------------------------
		/**
		 * Remplace %1, %2… en partant du plus grand indice, pour que %10 ne soit
		 * pas mangé par %1. Les tableaux sont joints, comme le fait _t().
		 */
		private static function interpolate(string $str, array $args) : string {
			if (!$args) { return $str; }
			for ($i = count($args); $i >= 1; $i--) {
				$v = $args[$i - 1];
				$str = str_replace('%'.$i, is_array($v) ? join('; ', $v) : (string)$v, $str);
			}
			return $str;
		}
	}
}
