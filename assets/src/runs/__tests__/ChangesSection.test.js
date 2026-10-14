/**
 * 0.4 S3.2: the report's Changes section. It renders what the server built,
 * asks for a preview before any undo, shows every outcome in words (and the
 * three-way drift table), and falls back to one line without Belayline Agent Governance 0.5.
 */

import fs from 'fs';
import path from 'path';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import ReportView from '../components/ReportView';
import ChangesSection from '../components/ChangesSection';

jest.mock( '@wordpress/api-fetch' );

const section = ( over = {} ) => ( {
	mode: 'undo',
	nonce: 'n1',
	changes: [
		{
			id: 11,
			object_type: 'post',
			object_id: '12',
			label: 'Launch Day',
			diff: [ { field: 'post_title', before: 'Old', after: 'New' } ],
			restorable: true,
			reason: null,
			reason_label: '',
			restored: false,
		},
		{
			id: 12,
			object_type: 'order',
			object_id: '9',
			label: '',
			diff: [],
			restorable: false,
			reason: 'irreversible',
			reason_label: "This kind of change can't be undone.",
			restored: false,
		},
	],
	...over,
} );

beforeEach( () => apiFetch.mockReset() );

describe( 'Changes section (Belayline Agent Governance 0.5 mode)', () => {
	it( 'lists each object with its diff and a reason label for what cannot be undone', () => {
		render( <ChangesSection runId={ 5 } section={ section() } /> );

		expect( screen.getByText( 'Launch Day' ) ).toBeInTheDocument();
		expect( screen.getByText( 'New' ) ).toBeInTheDocument();
		expect( screen.getByText( 'order #9' ) ).toBeInTheDocument();
		expect( screen.getByText( "This kind of change can't be undone." ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'button', { name: /^Undo [A-Z]/ } ) ).toHaveLength( 1 );
		expect( screen.getByRole( 'button', { name: 'Undo this run' } ) ).toBeInTheDocument();
	} );

	it( 'previews first and only undoes after the confirm click', async () => {
		apiFetch.mockResolvedValueOnce( { items: [ { change_id: 11, object_type: 'post', object_id: '12', label: 'Launch Day', status: 'restorable', reason_label: '', drift: [] } ] } );
		apiFetch.mockResolvedValueOnce( {
			status: 'complete',
			reason: null,
			message: '',
			items: [ { change_id: 11, object_type: 'post', object_id: '12', label: 'Launch Day', status: 'restored', reason_label: '', drift: [] } ],
			section: section( { changes: [ { ...section().changes[ 0 ], restored: true } ] } ),
		} );
		render( <ChangesSection runId={ 5 } section={ section() } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Undo this run' } ) );

		const dialog = await screen.findByRole( 'dialog' );
		expect( within( dialog ).getByText( 'Will be restored' ) ).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( { path: '/specflux-site-agent/v1/runs/5/changes/preview', method: 'POST', data: { nonce: 'n1' } } );

		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Confirm undo' } ) );

		await waitFor( () => expect( screen.getByText( 'Undone.' ) ).toBeInTheDocument() );
		expect( apiFetch.mock.calls[ 1 ][ 0 ] ).toMatchObject( { path: '/specflux-site-agent/v1/runs/5/changes/undo', data: { nonce: 'n1' } } );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Undo this run' } ) ).toBeNull();
	} );

	it( 'cancelling the dialog never sends an undo', async () => {
		apiFetch.mockResolvedValueOnce( { items: [] } );
		render( <ChangesSection runId={ 5 } section={ section() } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Undo this run' } ) );
		const dialog = await screen.findByRole( 'dialog' );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Cancel' } ) );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'a per-object Undo sends that change id', async () => {
		apiFetch.mockResolvedValueOnce( { items: [] } );
		render( <ChangesSection runId={ 5 } section={ section() } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Undo Launch Day' } ) );

		await screen.findByRole( 'dialog' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { nonce: 'n1', change_id: 11 } );
	} );

	it( 'renders skipped reasons, where it stopped, and the three-way drift table', async () => {
		apiFetch.mockResolvedValueOnce( { items: [] } );
		apiFetch.mockResolvedValueOnce( {
			status: 'partial',
			reason: null,
			message: '',
			items: [
				{ change_id: 12, object_type: 'order', object_id: '9', label: '', status: 'skipped', reason: 'irreversible', reason_label: "This kind of change can't be undone.", drift: [] },
				{
					change_id: 11,
					object_type: 'post',
					object_id: '12',
					label: 'Launch Day',
					status: 'failed',
					reason: 'drift',
					reason_label: 'Changed since the run. Restoring would overwrite newer work.',
					drift: [ { field: 'post_title', before: 'Old', after: 'New', current: 'Edited since' } ],
				},
				{ change_id: 13, object_type: 'post', object_id: '14', label: 'Later', status: 'pending', reason_label: '', drift: [] },
			],
			section: section(),
		} );
		render( <ChangesSection runId={ 5 } section={ section() } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo this run' } ) );
		fireEvent.click( within( await screen.findByRole( 'dialog' ) ).getByRole( 'button', { name: 'Confirm undo' } ) );

		const outcome = await screen.findByRole( 'status' );
		expect( within( outcome ).getByText( 'Partly undone. It stopped before the end.' ) ).toBeInTheDocument();
		expect( within( outcome ).getByText( 'Skipped' ) ).toBeInTheDocument();
		expect( within( outcome ).getByText( 'Stopped here' ) ).toBeInTheDocument();
		expect( within( outcome ).getByText( 'Not attempted' ) ).toBeInTheDocument();
		const drift = within( outcome ).getByRole( 'table' );
		expect( within( drift ).getAllByRole( 'columnheader' ).map( ( h ) => h.textContent ) ).toEqual( [ 'Field', 'Before the run', 'After the run', 'Now' ] );
		expect( within( drift ).getByText( 'Edited since' ) ).toBeInTheDocument();
	} );

	it( 'says a forbidden undo was refused and why', async () => {
		apiFetch.mockResolvedValueOnce( { items: [] } );
		apiFetch.mockResolvedValueOnce( { status: 'forbidden', reason: 'agent_context', message: 'An undo must come from a person, not an agent.', items: [] } );
		render( <ChangesSection runId={ 5 } section={ section() } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo this run' } ) );
		fireEvent.click( within( await screen.findByRole( 'dialog' ) ).getByRole( 'button', { name: 'Confirm undo' } ) );

		expect( await screen.findByText( 'This undo was refused.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'An undo must come from a person, not an agent.' ) ).toBeInTheDocument();
	} );

	it( 'shows a failed request as an alert', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'This page has expired. Reload it to continue.' ) );
		render( <ChangesSection runId={ 5 } section={ section() } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo this run' } ) );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent( 'This page has expired' );
	} );

	it( 'renders object data as text and marks it as data for the pseudo-locale gate', () => {
		const evil = section( { changes: [ { ...section().changes[ 0 ], label: '<img src=x onerror=alert(1)>' } ] } );
		const { container } = render( <ChangesSection runId={ 5 } section={ evil } /> );

		expect( container.querySelector( 'img' ) ).toBeNull();
		const name = container.querySelector( '.specflux-site-agent-changes-name' );
		expect( name ).toHaveAttribute( 'dir', 'auto' );
		expect( name ).toHaveAttribute( 'data-specflux-site-agent-content' );
	} );

	it( 'styles with logical properties only (RTL-safe)', () => {
		const css = fs.readFileSync( path.join( __dirname, '..', 'style.css' ), 'utf8' );
		const block = css.slice( css.indexOf( 'Changes and undo in the report (0.4 S3.2)' ) );
		expect( block ).not.toMatch( /\b(margin|padding|border)-(left|right)\b|\b(left|right)\s*:/ );
		expect( block ).not.toMatch( /text-align:\s*(left|right)/ );
	} );
} );

describe( 'Changes section in the report', () => {
	const report = {
		summary: 'x',
		changes: [
			{ object_type: 'post', object_id: '12', title: 'Launch Day', status: 'draft', edit_url: null, verified: true },
			{ object_type: 'post', object_id: '13', title: 'No revisions', status: 'draft', edit_url: null, verified: true },
		],
		gate_mode: 'built_in',
		withheld_roles: [],
	};

	it( 'fallback: a Revisions link on rows that have one and one "Undo needs Belayline Agent Governance" line, no undo', () => {
		const fallback = {
			mode: 'revisions',
			revisions: { 12: 'https://example.test/wp-admin/revision.php?revision=99' },
			note: 'Undo needs Belayline Agent Governance',
		};
		render( <ReportView report={ report } steps={ [] } runId={ 5 } changesSection={ fallback } /> );

		const link = screen.getByRole( 'link', { name: 'Revisions of Launch Day' } );
		expect( link ).toHaveAttribute( 'href', 'https://example.test/wp-admin/revision.php?revision=99' );
		expect( screen.getAllByRole( 'link', { name: /^Revisions/ } ) ).toHaveLength( 1 );
		expect( screen.getAllByText( 'Undo needs Belayline Agent Governance' ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'button', { name: /Undo/ } ) ).toBeNull();
	} );

	it( 'a non-http revisions url is not rendered as a link', () => {
		const bad = { mode: 'revisions', revisions: { 12: 'javascript:alert(1)' }, note: 'Undo needs Belayline Agent Governance' };
		render( <ReportView report={ report } steps={ [] } runId={ 5 } changesSection={ bad } /> );

		expect( screen.queryByRole( 'link', { name: /^Revisions/ } ) ).toBeNull();
	} );

	it( 'no section: nothing about undo appears', () => {
		render( <ReportView report={ report } steps={ [] } runId={ 5 } /> );

		expect( screen.queryByText( 'Undo needs Belayline Agent Governance' ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: /Undo/ } ) ).toBeNull();
	} );

	it( 'undo mode shows the Changes heading inside the report', () => {
		render( <ReportView report={ { ...report, gate_mode: 'agent_safety' } } steps={ [] } runId={ 5 } changesSection={ section() } /> );

		expect( screen.getByRole( 'heading', { name: 'Changes' } ) ).toBeInTheDocument();
	} );
} );
