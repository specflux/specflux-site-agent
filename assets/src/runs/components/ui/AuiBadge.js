import cx from './cx';

const TONES = [ 'ok', 'warn', 'bad', 'info', 'ink' ];

/**
 * Kit badge. `tone` is one of ok|warn|bad|info|ink; anything else is the
 * neutral badge.
 */
export default function AuiBadge( { tone, className, children, ...rest } ) {
	return (
		<span className={ cx( 'aui-badge', TONES.includes( tone ) && `aui-badge--${ tone }`, className ) } { ...rest }>
			{ children }
		</span>
	);
}
