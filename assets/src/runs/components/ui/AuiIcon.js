/**
 * A kit icon. The PHP shell prints the icon sprite inline once per page, so
 * `#aui-i-<name>` resolves without a network request. Decorative by default.
 *
 * @param {Object} props
 * @param {string} props.name      Icon name without the `aui-i-` prefix (e.g. `copy`).
 * @param {string} [props.className] Extra classes.
 */
export default function AuiIcon( { name, className = '' } ) {
	return (
		<svg className={ ( 'aui-i ' + className ).trim() } aria-hidden="true" focusable="false">
			<use href={ `#aui-i-${ name }` } />
		</svg>
	);
}
