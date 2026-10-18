import AuiIcon from './AuiIcon';
import cx from './cx';

const ICONS = { info: 'info', success: 'check', warning: 'warning', error: 'warning' };

/**
 * Kit notice. `tone`: info | success | warning | error. The children sit in a
 * single block so the icon and text align as the kit expects.
 */
export default function AuiNotice( { tone = 'info', icon, className, children, ...rest } ) {
	return (
		<div className={ cx( 'aui-notice', `aui-notice--${ tone }`, className ) } { ...rest }>
			<AuiIcon name={ icon || ICONS[ tone ] || 'info' } />
			<div className="specflux-site-agent-notice-body">{ children }</div>
		</div>
	);
}
