import cx from './cx';

/**
 * Kit button. `variant`: primary | secondary | ghost | danger | danger-outline.
 * Defaults to `type="button"` so a button never submits a surrounding form.
 * A button passing both `aria-expanded` and `aria-controls` is a React-owned toggle, so it gets
 * `data-aui-manual` and the kit's delegated click handler leaves it alone (otherwise it would flip it back).
 */
export default function AuiButton( { variant = 'secondary', size, type = 'button', className, children, ...rest } ) {
	const manual = rest[ 'aria-expanded' ] !== undefined && rest[ 'aria-controls' ] !== undefined ? { 'data-aui-manual': '' } : null;
	return (
		<button type={ type } className={ cx( 'aui-btn', `aui-btn--${ variant }`, size && `aui-btn--${ size }`, className ) } { ...manual } { ...rest }>
			{ children }
		</button>
	);
}
