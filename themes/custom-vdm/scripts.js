/**
 * BookStack Custom Theme - Enhanced User Experience
 * 
 * This JavaScript file adds functionality to improve navigation and usability:
 * - Pagination for books with many pages
 * - Enhanced collapsible chapter functionality
 * - Persistent state for expanded/collapsed chapters
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize pagination for books with many pages
    initPagePagination();
    
    // Enhance collapsible chapter functionality
    enhanceChapterCollapsible();
    
    // Add visual hierarchy indicators to breadcrumbs
    enhanceBreadcrumbs();
    
    // Initialize tooltips for navigation help
    initNavigationTooltips();
});

/**
 * Initializes pagination for books with many pages
 * This helps users navigate books that contain a large number of pages
 */
function initPagePagination() {
    // Find page lists that have more than 10 pages
    const pageLists = document.querySelectorAll('.book-content .page-list');
    
    pageLists.forEach(function(pageList) {
        const pages = pageList.querySelectorAll('li');
        
        // Only add pagination if there are more than 10 pages
        if (pages.length > 10) {
            // Create pagination container
            const paginationContainer = document.createElement('div');
            paginationContainer.className = 'page-list-pagination';
            
            // Calculate number of pages needed (10 items per page)
            const pageCount = Math.ceil(pages.length / 10);
            
            // Create pagination buttons
            for (let i = 0; i < pageCount; i++) {
                const pageButton = document.createElement('button');
                pageButton.textContent = i + 1;
                pageButton.dataset.page = i;
                
                if (i === 0) {
                    pageButton.className = 'active';
                }
                
                pageButton.addEventListener('click', function() {
                    // Update active button
                    paginationContainer.querySelectorAll('button').forEach(btn => {
                        btn.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    // Show only pages for the selected page number
                    const startIndex = i * 10;
                    const endIndex = Math.min(startIndex + 10, pages.length);
                    
                    pages.forEach((page, index) => {
                        if (index >= startIndex && index < endIndex) {
                            page.style.display = '';
                        } else {
                            page.style.display = 'none';
                        }
                    });
                });
                
                paginationContainer.appendChild(pageButton);
            }
            
            // Insert pagination after the page list
            pageList.parentNode.insertBefore(paginationContainer, pageList.nextSibling);
            
            // Initially show only the first 10 pages
            pages.forEach((page, index) => {
                if (index >= 10) {
                    page.style.display = 'none';
                }
            });
        }
    });
}

/**
 * Enhances the collapsible chapter functionality
 * Adds visual indicators and saves state between visits
 */
function enhanceChapterCollapsible() {
    const chapterToggles = document.querySelectorAll('.chapter-contents-toggle');
    
    chapterToggles.forEach(function(toggle) {
        // Get chapter ID for storage
        const chapterId = toggle.closest('.chapter').dataset.id || toggle.closest('.chapter').id;
        
        // Add icon to indicate expandable
        const icon = document.createElement('span');
        icon.className = 'icon';
        icon.innerHTML = toggle.classList.contains('open') ? '▼' : '►';
        toggle.prepend(icon);
        
        // Check localStorage for saved state
        if (chapterId && localStorage.getItem('chapter-' + chapterId) === 'open') {
            toggle.classList.add('open');
            const content = toggle.nextElementSibling;
            if (content && content.classList.contains('chapter-contents')) {
                content.style.display = 'block';
                icon.innerHTML = '▼';
            }
        }
        
        // Override click handler
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            const content = this.nextElementSibling;
            if (content && content.classList.contains('chapter-contents')) {
                if (content.style.display === 'block') {
                    content.style.display = 'none';
                    this.classList.remove('open');
                    icon.innerHTML = '►';
                    
                    // Save state
                    if (chapterId) {
                        localStorage.setItem('chapter-' + chapterId, 'closed');
                    }
                } else {
                    content.style.display = 'block';
                    this.classList.add('open');
                    icon.innerHTML = '▼';
                    
                    // Save state
                    if (chapterId) {
                        localStorage.setItem('chapter-' + chapterId, 'open');
                    }
                }
            }
        });
    });
}

/**
 * Enhances breadcrumbs with visual hierarchy indicators
 */
function enhanceBreadcrumbs() {
    const breadcrumbs = document.querySelector('.breadcrumbs');
    
    if (breadcrumbs) {
        const links = breadcrumbs.querySelectorAll('a');
        
        links.forEach(function(link, index) {
            // Add appropriate icon based on hierarchy level
            const icon = document.createElement('span');
            icon.className = 'icon mr-xs';
            
            if (index === 0 && links.length > 1) {
                // First item is likely a shelf
                icon.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M9 3v15h3V3H9zm-5 0v15h3V3H4zm10 0v15h3V3h-3zm5 0v15h3V3h-3z"/></svg>';
                link.classList.add('text-bookshelf');
            } else if ((index === 0 && links.length === 1) || (index === 1 && links.length > 1)) {
                // Book
                icon.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M21 4H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 14H3V6h18v12z"/></svg>';
                link.classList.add('text-book');
            } else if (index === links.length - 2) {
                // Chapter
                icon.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M3 3h18v2H3V3zm0 4h18v2H3V7zm0 4h18v2H3v-2zm0 4h18v2H3v-2zm0 4h18v2H3v-2z"/></svg>';
                link.classList.add('text-chapter');
            } else if (index === links.length - 1) {
                // Page
                icon.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM18 20H6V4h7v5h5v11z"/></svg>';
                link.classList.add('text-page');
            }
            
            link.prepend(icon);
        });
    }
}

/**
 * Initializes tooltips for navigation help
 */
function initNavigationTooltips() {
    // Add navigation tips box to book and shelf view pages
    const contentContainer = document.querySelector('.content-wrap');
    
    if (contentContainer && (window.location.href.includes('/shelves/') || window.location.href.includes('/books/'))) {
        const tipsBox = document.createElement('div');
        tipsBox.className = 'navigation-tips mb-l';
        
        let tipsContent = '';
        
        if (window.location.href.includes('/shelves/')) {
            tipsContent = `
                <h4>Navigation Tips</h4>
                <ul>
                    <li>This shelf contains books related to a specific department or topic</li>
                    <li>Click on a book to view its chapters and pages</li>
                    <li>Use the search box above to find specific content</li>
                </ul>
            `;
        } else if (window.location.href.includes('/books/')) {
            tipsContent = `
                <h4>Navigation Tips</h4>
                <ul>
                    <li>This book contains chapters and/or pages related to a specific topic</li>
                    <li>Click on chapter titles to expand/collapse their contents</li>
                    <li>If there are many pages, use the pagination controls below the page list</li>
                </ul>
            `;
        }
        
        tipsBox.innerHTML = tipsContent;
        
        // Insert at the top of the content container
        contentContainer.insertBefore(tipsBox, contentContainer.firstChild);
    }
}