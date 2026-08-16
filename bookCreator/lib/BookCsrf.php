<?php
/* Book Creator plugin for CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */

/**
 * Cross-site request forgery protection, owned by the plugin.
 *
 * CollectiveAccess validates its own tokens inside BaseEditorController, which
 * these controllers do not extend; inheriting from it to gain that single
 * behaviour would drag in an editing lifecycle the plugin has no use for. So
 * the mechanism lives here, small and readable, and every state-changing action
 * validates explicitly.
 *
 * The token is per session, not per form: a per-form token breaks the moment a
 * user opens two sections in two tabs, and an editor working on a book does
 * exactly that.
 */
class BookCsrf {

	const SESSION_KEY = 'bookCreator_csrf';
	const FIELD_NAME  = 'bookCreatorToken';

	/**
	 * The token for this session, created on first use.
	 *
	 * Uses the CollectiveAccess session when one is available so the value
	 * survives the same way the rest of the session does, and falls back to
	 * $_SESSION for the rare context where it is not.
	 */
	public static function token() {
		$token = self::read();
		if (is_string($token) && strlen($token) === 64) { return $token; }

		$token = bin2hex(random_bytes(32));
		self::write($token);
		return $token;
	}

	/** Hidden field to drop inside a form. */
	public static function field() {
		return '<input type="hidden" name="'.self::FIELD_NAME.'" value="'
			.htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8').'" />';
	}

	/** The token as a request parameter, for the links that must carry it. */
	public static function param() {
		return [self::FIELD_NAME => self::token()];
	}

	/**
	 * Whether the request carries the right token.
	 *
	 * Compared with hash_equals() rather than == : a plain comparison leaks the
	 * position of the first differing byte through its timing, which is enough
	 * to reconstruct a token given enough attempts.
	 */
	public static function isValid($request) {
		$submitted = $request ? $request->getParameter(self::FIELD_NAME, pString) : null;
		if (!is_string($submitted) || $submitted === '') { return false; }

		$expected = self::read();
		if (!is_string($expected) || $expected === '') { return false; }

		return hash_equals($expected, $submitted);
	}

	# -------------------------------------------------------

	private static function read() {
		if (class_exists('Session') && method_exists('Session', 'getVar')) {
			return Session::getVar(self::SESSION_KEY);
		}
		return $_SESSION[self::SESSION_KEY] ?? null;
	}

	private static function write($token) {
		if (class_exists('Session') && method_exists('Session', 'setVar')) {
			Session::setVar(self::SESSION_KEY, $token);
			return;
		}
		$_SESSION[self::SESSION_KEY] = $token;
	}
}
