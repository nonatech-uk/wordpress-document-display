/**
 * DocDisplay - Scroll and Highlight functionality
 *
 * When a page loads with a #doc-* anchor in the URL, this script:
 * 1. Finds the document row with that ID
 * 2. Scrolls to it smoothly
 * 3. Adds a highlight animation
 * 4. Also highlights any associated annexes row
 */
(function() {
    'use strict';

    /**
     * Initialize on DOM ready
     */
    function init() {
        // Check if there's a doc anchor in the URL
        var hash = window.location.hash;
        if (!hash || !hash.startsWith('#doc-')) {
            return;
        }

        var anchorId = hash.substring(1); // Remove the #
        var targetRow = document.getElementById(anchorId);

        if (!targetRow) {
            return;
        }

        // Scroll to the element with some offset for header
        scrollToElement(targetRow);

        // Add highlight class after a short delay to ensure scroll completes
        setTimeout(function() {
            highlightDocument(targetRow, anchorId);
        }, 100);
    }

    /**
     * Scroll to an element with offset for fixed headers
     *
     * @param {HTMLElement} element Element to scroll to
     */
    function scrollToElement(element) {
        // Get the element's position
        var rect = element.getBoundingClientRect();
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;

        // Calculate target position with offset for any fixed headers
        // Using 100px as a safe default offset
        var targetPosition = rect.top + scrollTop - 100;

        // Smooth scroll
        window.scrollTo({
            top: targetPosition,
            behavior: 'smooth'
        });
    }

    /**
     * Highlight a document row and its annexes
     *
     * @param {HTMLElement} row Document table row
     * @param {string} anchorId Anchor ID for finding annexes
     */
    function highlightDocument(row, anchorId) {
        // Add highlight class to the document row
        row.classList.add('docdisplay-highlight');

        // Find and highlight any associated annexes row
        var annexesRow = document.querySelector('.docdisplay-annexes-for-' + anchorId);
        if (annexesRow) {
            annexesRow.classList.add('docdisplay-highlight');
        }

        // Remove highlight classes after animation completes
        setTimeout(function() {
            row.classList.remove('docdisplay-highlight');
            if (annexesRow) {
                annexesRow.classList.remove('docdisplay-highlight');
            }
        }, 3000);
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Also handle hashchange events (for SPA-like navigation)
    window.addEventListener('hashchange', init);
})();
