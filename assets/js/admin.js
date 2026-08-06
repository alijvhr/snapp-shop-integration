(function () {
    'use strict';

    const input = document.getElementById('snapp-category-search');
    const table = document.getElementById('snapp-category-table');
    const count = document.getElementById('snapp-category-count');
    const empty = document.getElementById('snapp-category-empty');
    const pagination = document.getElementById('snapp-category-pagination');

    if (!input || !table || !count || !empty || !pagination) {
        return;
    }

    const pageSize = 50;
    const leaves = Array.from(table.querySelectorAll('.snapp-category-leaf'));
    const groups = Array.from(table.querySelectorAll('.snapp-category-group'));
    let filteredLeaves = leaves;
    let currentPage = 1;

    function renderPagination(pageCount) {
        pagination.innerHTML = '';

        if (pageCount < 2) {
            return;
        }

        const addButton = function (label, page, disabled, current) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-small snapp-category-pagination__button';
            button.textContent = label;
            button.disabled = disabled;
            if (current) {
                button.classList.add('is-current');
                button.setAttribute('aria-current', 'page');
            }
            button.addEventListener('click', function () {
                currentPage = page;
                render();
            });
            pagination.appendChild(button);
        };

        addButton('Previous', currentPage - 1, currentPage === 1, false);

        for (let page = 1; page <= pageCount; page++) {
            addButton(String(page), page, false, page === currentPage);
        }

        addButton('Next', currentPage + 1, currentPage === pageCount, false);
    }

    function render() {
        const pageCount = Math.max(1, Math.ceil(filteredLeaves.length / pageSize));
        currentPage = Math.min(currentPage, pageCount);
        const first = (currentPage - 1) * pageSize;
        const pageLeaves = filteredLeaves.slice(first, first + pageSize);
        const pageLeafSet = new Set(pageLeaves);

        leaves.forEach(function (row) {
            row.hidden = !pageLeafSet.has(row);
        });

        groups.forEach(function (group) {
            const groupId = group.dataset.categoryGroup;
            group.hidden = !pageLeaves.some(function (row) {
                return row.dataset.categoryGroup === groupId;
            });
        });

        empty.hidden = filteredLeaves.length !== 0;
        const shownFrom = filteredLeaves.length === 0 ? 0 : first + 1;
        const shownTo = Math.min(first + pageSize, filteredLeaves.length);
        count.textContent = filteredLeaves.length === 0
            ? 'Showing 0 categories'
            : 'Showing ' + shownFrom + '-' + shownTo + ' of ' + filteredLeaves.length + ' categories';
        renderPagination(pageCount);
    }

    input.addEventListener('input', function () {
        const query = input.value.trim().toLocaleLowerCase();
        filteredLeaves = leaves.filter(function (row) {
            return query === '' || (row.dataset.categorySearch || '').toLocaleLowerCase().indexOf(query) !== -1;
        });
        currentPage = 1;
        render();
    });

    render();
}());
