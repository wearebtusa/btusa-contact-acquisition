( function () {
	'use strict';

	const selectAll = document.getElementById( 'btusa-select-all-users' );
	const userBoxes = Array.from( document.querySelectorAll( '.btusa-user-checkbox' ) );
	const count = document.getElementById( 'btusa-selected-user-count' );

	if ( ! count || ! userBoxes.length ) {
		return;
	}

	const updateSelection = function () {
		const selected = userBoxes.filter( function ( checkbox ) {
			return checkbox.checked;
		} ).length;

		count.textContent = String( selected );
		if ( selectAll ) {
			selectAll.checked = selected === userBoxes.length;
			selectAll.indeterminate = selected > 0 && selected < userBoxes.length;
		}
	};

	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			userBoxes.forEach( function ( checkbox ) {
				checkbox.checked = selectAll.checked;
			} );
			updateSelection();
		} );
	}

	userBoxes.forEach( function ( checkbox ) {
		checkbox.addEventListener( 'change', updateSelection );
	} );

	updateSelection();
}() );
