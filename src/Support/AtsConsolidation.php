<?php
/**
 * One-time consolidation of the legacy ATS anti-spam mu-plugin into UMS settings.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Support;

use Marinski\UserManagementSuite\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Imports the mu-plugin's rules into ums_settings so the mu-plugin (and its
 * bugs) can be deleted. Runs once, keyed by a flag option, and is idempotent.
 */
class AtsConsolidation {

	/**
	 * Flag option set once the one-time import has completed.
	 */
	const FLAG = 'ums_ats_consolidated';

	/**
	 * The mu-plugin's keyword list, lowercased and deduped.
	 *
	 * Allowed to drift: once imported the settings list is authoritative. The
	 * live function (if the mu-plugin is still installed) takes precedence so a
	 * storage migration cannot silently drop a keyword the mu-plugin blocks
	 * today, and a hard-coded copy keeps parity if the mu-plugin is removed
	 * before the migration runs.
	 *
	 * @var string[]
	 */
	const SEED_KEYWORDS = array(
		'1xbet',
		'bet88',
		'8xbet',
		'go88',
		'78win',
		'casino',
		'28bet',
		'red88',
		'go99',
		'iwin',
		'nowgoal',
		'oxbet',
		'cakhia',
		'bongda',
		'bbwin',
		'jun88',
		'm88',
		'fb88',
		'typhu',
		'shbet',
		'sunwin',
		'b52',
		'tf88',
		'hello868',
		'789',
		'ku777',
		'w88',
		'mibet',
		'hj88',
		'kubet',
		'bj88',
		'ae688',
		'r88',
		'gamebai',
		'banca',
		'xoso',
		'lottery',
		'bet',
		'vip',
		'dissertation',
		'evisa',
		'capcut',
		'91club',
		'assignmenthelp',
		'essaywriting',
		'buyessay',
		'uniassignment',
		'studyhelper',
		'modapk',
		'datingads',
		'immigrationvisa',
		'nursingessay',
		'yumeustechnologies',
		'speedypaper',
		'123movies',
		'thesnapinsta',
		'hh98',
		'jj55',
		'pkr98',
		'okpkr',
		'we999',
		'g555',
		'j188',
		'bappam',
		'pkgames',
		'pak games',
		'snapinsta',
		'eo2bet',
		'e2bet',
		'ko66',
		'ai88',
		'yabo',
		'亚博',
		'luongson',
		'sexdoll',
		'sexdollpartner',
		'visionfreedom',
		'mnscredit',
		'watsondavid',
		'brianlara',
		'crackstreams',
		'acrackstreams',
		'ambslot',
		'vansonjackets',
		'curtainsandblinds',
		'vayucbd',
		'datehype',
		'mark423256',
		'jordan6566',
		'neha',
		'sprintzeal',
		'apkjili',
		'888idr',
		'emmaclark',
		'wearableoutfit',
		'pinap',
		'bestrealdoll',
		'expostandzone',
		'eaglepatches',
		'oliviawilliam',
		'oliviaeliana',
		'dennyypoke',
		'yordwindows',
		'driftcarrental',
		'kiwieuroparts',
		'airlineairport',
		'raufaamir',
		'antoniadam',
		'dasik22',
		'marshall',
		'sendleradam',
		'shahidusamn',
		'davidcarter',
		'fpsstructures',
		'skillsdigi33',
		'sexdoll',
		'sexdolltech',
		'lovedoll',
		'realdoll',
		'sexdollpartner',
		'sexdollshop',
		'torsodoll',
		'sexdollsgirl',
		'happydoll',
		'seekhappydoll',
		'bestrealdoll',
		'buymanualbacklinks',
		'gmblwiki',
		'jarvisreach',
		'vastu',
		'occult',
		'saruoccult',
		'neilestes',
		'indu75691',
		'horik',
		'linksys extender',
		'orbi',
		'pusokei',
		'floppydata',
		'levelupcasino',
		'lasvegasjacket',
		'pvcpatches',
		'theeaglepatches',
		'marstranslation',
		'noida escorts',
		'beautiqueen',
		'christianmohapatra',
		'kinsey',
		'puravi',
		'davidhamilton',
		'marikfol',
		'johnhargen',
		'tintucsl',
		'jenniferdavis',
		'harlanbixby',
		'davidbrown',
		'marilynburnet',
		'pvcpatchesuk',
		'richardroseline',
		'johnmichael',
		'ariacarter',
		'divyarawat',
		'janylim',
		'peggiestiedemann',
	);

	/**
	 * The mu-plugin's disposable-mail domain list.
	 *
	 * @var string[]
	 */
	const SEED_DISPOSABLE_DOMAINS = array(
		'mailinator.com',
		'guerrillamail.com',
		'guerrillamailbox.com',
		'temp-mail.org',
		'tempmail.com',
		'getnada.com',
		'dispostable.com',
		'10minutemail.com',
		'trashmail.com',
		'mailnesia.com',
		'mintemail.com',
		'throwawaymail.com',
		'yopmail.com',
		'boxomail.com',
		'mailtemp.net',
		'spamgourmet.com',
		'sharklasers.com',
		'mailcatch.com',
		'jetable.org',
		'meltmail.com',
		'emailondeck.com',
		'fakeinbox.com',
		'tmail.ws',
		'kuku.mail',
		'33mail.com',
		'emailnator.com',
		'maillazy.com',
		'trashmail.me',
		'tempinbox.com',
		'tempr.email',
		'spambox.us',
		'internxt.org',
		'moakt.com',
		'linute.com',
		'mailsac.com',
		'mailgw.com',
		'inboxbear.com',
		'keepmymail.com',
		'einrot.com',
		'fakemail.net',
		'mixmail.com',
		'cliptik.net',
		'dayrep.com',
		'cuvox.de',
		'armyspy.com',
		'gibtelecom.net',
		'gustr.com',
		'cuvox.de',
		'juyou.com',
		'zoho.com.email',
		'hidesit.net',
		'hidesit.com',
		'sharklasers.com',
		'mailinator2.com',
	);

	/**
	 * Seed keyword list, preferring the live mu-plugin when present.
	 *
	 * @return string[]
	 */
	public static function keywords() {
		if ( function_exists( 'ats_reg_bad_keywords' ) ) {
			return self::normalize( ats_reg_bad_keywords() );
		}

		return array_values( self::SEED_KEYWORDS );
	}

	/**
	 * Seed disposable-domain list, preferring the live mu-plugin when present.
	 *
	 * @return string[]
	 */
	public static function disposable_domains() {
		if ( function_exists( 'ats_reg_bad_domains' ) ) {
			return self::normalize( ats_reg_bad_domains() );
		}

		return array_values( self::SEED_DISPOSABLE_DOMAINS );
	}

	/**
	 * One-time import into ums_settings.
	 *
	 * Idempotent: merging uses unions, so re-running never duplicates, and the
	 * FLAG option short-circuits the whole call in the common case (an
	 * autoloaded option read).
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( get_option( self::FLAG, false ) ) {
			return;
		}

		$repo = new SettingsRepository();
		$all  = $repo->all();
		$v    = isset( $all['verification'] ) ? $all['verification'] : array();

		$domains = isset( $v['blocked_domains'] ) ? (array) $v['blocked_domains'] : array();
		$keys    = isset( $v['blocked_keywords'] ) ? (array) $v['blocked_keywords'] : array();
		$ips     = isset( $v['blocked_ips'] ) ? (array) $v['blocked_ips'] : array();

		$v['blocked_domains']  = self::merge( $domains, self::disposable_domains() );
		$v['blocked_keywords'] = self::merge( $keys, self::keywords() );
		$v['blocked_ips']      = self::merge( $ips, get_option( 'ats_blocked_ips', array() ) );

		// track_registration_ip (recording the registrant IP, like the mu-plugin
		// did) defaults to true in SettingsRepository::defaults(), so it needs no
		// explicit write here.

		$all['verification'] = $v;
		$repo->save( $all );

		update_option( self::FLAG, time() );
	}

	/**
	 * Lowercase, trim, and dedupe a rule list.
	 *
	 * @param mixed $raw Rule list.
	 * @return string[]
	 */
	private static function normalize( $raw ) {
		$out = array();
		foreach ( (array) $raw as $item ) {
			$item = strtolower( trim( (string) $item ) );
			if ( '' !== $item && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Merge a stored list with a seed list, preserving stored order.
	 *
	 * @param array<mixed> $stored Stored values.
	 * @param array<mixed> $seed   Values to add.
	 * @return string[]
	 */
	private static function merge( array $stored, $seed ) {
		return array_values( array_unique( array_merge( self::normalize( $stored ), self::normalize( $seed ) ), SORT_STRING ) );
	}
}
