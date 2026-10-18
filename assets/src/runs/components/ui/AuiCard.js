import cx from './cx';

/** Kit card. `as` picks the element (div, section, aside, main, ...). */
export default function AuiCard( { as: Tag = 'div', className, children, ...rest } ) {
	return (
		<Tag className={ cx( 'aui-card', className ) } { ...rest }>
			{ children }
		</Tag>
	);
}
