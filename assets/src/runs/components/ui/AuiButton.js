import cx from './cx';

/**
 * Kit button. `variant`: primary | secondary | ghost | danger | danger-outline.
 * Defaults to `type="button"` so a button never submits a surrounding form.
 */
export default function AuiButton( { variant = 'secondary', size, type = 'button', className, children, ...rest } ) {
	return (
		<button type={ type } className={ cx( 'aui-btn', `aui-btn--${ variant }`, size && `aui-btn--${ size }`, className ) } { ...rest }>
			{ children }
		</button>
	);
}
