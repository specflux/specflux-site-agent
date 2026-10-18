import cx from './cx';

/**
 * Kit tablist. Each tab is `{ key, label, count?, tone? }`; the count renders
 * as `(n)` inside `.aui-count`, so the accessible name stays "Label (n)".
 */
export default function AuiTabs( { tabs, active, onChange, className, tabClassName } ) {
	return (
		<div className={ cx( 'aui-tablist', className ) } role="tablist">
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.key }
					type="button"
					role="tab"
					aria-selected={ active === tab.key }
					className={ cx( tabClassName, active === tab.key && 'is-active' ) }
					onClick={ () => onChange( tab.key ) }
				>
					{ tab.label }
					{ undefined !== tab.count && (
						<>
							{ ' ' }
							<span className={ cx( 'aui-count', 'warn' === tab.tone && 'aui-count--warn' ) }>{ `(${ tab.count })` }</span>
						</>
					) }
				</button>
			) ) }
		</div>
	);
}
