(function () {
    'use strict';

    const input = document.getElementById('snapp-category-search');
    const table = document.getElementById('snapp-category-table');
    const body = document.getElementById('snapp-category-body');
    const count = document.getElementById('snapp-category-count');
    const empty = document.getElementById('snapp-category-empty');
    const pagination = document.getElementById('snapp-category-pagination');
    const dataElement = document.getElementById('snapp-category-data');
    const mappings = document.getElementById('snapp-category-mappings');
    const form = table ? table.closest('form') : null;

    if (!input || !table || !body || !count || !empty || !pagination || !dataElement || !mappings || !form) {
        return;
    }

    let data;
    try {
        data = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        return;
    }

    const pageSize = 50;
    const categories = Array.isArray(data.categories) ? data.categories : [];
    const wpCategories = Array.isArray(data.wpCategories) ? data.wpCategories : [];
    const groups = Array.isArray(data.groups) ? data.groups : [];
    const groupLabels = {};
    let filteredCategories = categories;
    let currentPage = 1;

    groups.forEach(function (group) {
        groupLabels[group.id] = group.label;
    });

    function createGroupRow(groupId) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        const label = document.createElement('span');
        const name = document.createElement('strong');

        row.className = 'snapp-category-group';
        row.dataset.categoryGroup = groupId;
        cell.colSpan = 3;
        label.className = 'snapp-category-group__label';
        label.textContent = 'Category group';
        name.className = 'snapp-category-group__name';
        name.textContent = groupLabels[groupId] || '';
        cell.appendChild(label);
        cell.appendChild(name);
        row.appendChild(cell);

        return row;
    }

    function createCategoryRow(category) {
        const row = document.createElement('tr');
        const categoryCell = document.createElement('td');
        const name = document.createElement('strong');
        const code = document.createElement('code');
        const mappingCell = document.createElement('td');
        const select = document.createElement('select');
        const dateCell = document.createElement('td');
        const blankOption = document.createElement('option');

        row.className = 'snapp-category-leaf';
        row.dataset.categoryGroup = category.groupId || '';
        row.dataset.categorySearch = category.search || '';

        name.className = 'snapp-category-leaf__name';
        name.textContent = category.label || '';
        code.textContent = category.id || '';
        categoryCell.appendChild(name);
        categoryCell.appendChild(code);

        select.dataset.categoryId = category.id || '';
        blankOption.value = '';
        blankOption.textContent = '\u2014 Do not include \u2014';
        select.appendChild(blankOption);
        wpCategories.forEach(function (wpCategory) {
            const option = document.createElement('option');
            option.value = wpCategory.id || '';
            option.textContent = wpCategory.name || '';
            select.appendChild(option);
        });
        select.value = category.mapping || '';
        select.addEventListener('change', function () {
            category.mapping = select.value;
        });
        mappingCell.appendChild(select);

        dateCell.textContent = category.includedAt || '\u2014';
        row.appendChild(categoryCell);
        row.appendChild(mappingCell);
        row.appendChild(dateCell);

        return row;
    }

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
        const pageCount = Math.max(1, Math.ceil(filteredCategories.length / pageSize));
        currentPage = Math.min(currentPage, pageCount);
        const first = (currentPage - 1) * pageSize;
        const pageCategories = filteredCategories.slice(first, first + pageSize);
        const renderedGroups = new Set();
        const fragment = document.createDocumentFragment();

        body.querySelectorAll('.snapp-category-generated').forEach(function (row) {
            row.remove();
        });

        pageCategories.forEach(function (category) {
            if (!renderedGroups.has(category.groupId)) {
                const groupRow = createGroupRow(category.groupId);
                groupRow.classList.add('snapp-category-generated');
                fragment.appendChild(groupRow);
                renderedGroups.add(category.groupId);
            }

            const categoryRow = createCategoryRow(category);
            categoryRow.classList.add('snapp-category-generated');
            fragment.appendChild(categoryRow);
        });

        body.insertBefore(fragment, empty);
        empty.hidden = filteredCategories.length !== 0;

        const shownFrom = filteredCategories.length === 0 ? 0 : first + 1;
        const shownTo = Math.min(first + pageSize, filteredCategories.length);
        count.textContent = filteredCategories.length === 0
            ? 'Showing 0 categories'
            : 'Showing ' + shownFrom + '-' + shownTo + ' of ' + filteredCategories.length + ' categories';
        renderPagination(pageCount);
    }

    function writeMappings() {
        mappings.innerHTML = '';
        categories.forEach(function (category) {
            if (!category.mapping) {
                return;
            }

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'mappings[' + category.id + ']';
            input.value = category.mapping;
            mappings.appendChild(input);
        });
    }

    input.addEventListener('input', function () {
        const query = input.value.trim().toLocaleLowerCase();
        filteredCategories = categories.filter(function (category) {
            return query === '' || (category.search || '').toLocaleLowerCase().indexOf(query) !== -1;
        });
        currentPage = 1;
        render();
    });

    form.addEventListener('submit', writeMappings);
    render();
}());
