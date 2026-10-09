// Relative to the page: bdo-site and bdo-api are sibling folders
const API = '../bdo-api/api';

// Page Status
let currentPage = 1;
let perPage     = 50;
let searchQuery = '';
let skillFilter = '';
let searchTimer = null;

// Pull Recipes from API and list
async function loadRecipes() {
    const list = document.getElementById('recipe-list');
    list.innerHTML = '<div class="loading">Loading Recipes...</div>';

    // Generate URL parameters
    let url = `${API}/recipes.php?source=cooking&page=${currentPage}&limit=${perPage}&with_ingredients=1`;
    if (searchQuery) url += `&search=${encodeURIComponent(searchQuery)}`;
    if (skillFilter) url += `&skill=${encodeURIComponent(skillFilter)}`;
    
    try {
        const res = await fetch(url);
        const data = await res.json();

        if (!data.data || data.data.length === 0) {
            list.innerHTML = '<div class="empty-state"><h3>No Recipes Found</h3></div>';
            return;     
        }

        // generate a row for every recipe
        // Promise.All: pull everything at same time, do not wait
        const rows = data.data.map(r => buildRecipeRow(r));
        list.innerHTML = rows.join('');

        // update pageing
        buildPagination(data.total_pages);

        // add tooltip in ingredient icons
        attachTooltips();

    } catch (err) {
        list.innerHTML = '<div class="empty-state"><h3>Failed to load recipes.</h3></div>';
        console.error(err);
    }
}

// single recipe HTML row
function buildRecipeRow(recipe) {
    
    const defaultIngs = (recipe.ingredients || []);

    const ingIcons = defaultIngs.map(ing => `
        <div class="ingredient-icon-wrap"
             data-item-id="${ing.item_id}"
             data-item-name="${ing.name}"
             data-item-icon="${ing.icon}"
             data-item-grade="${ing.grade}"
             data-item-grade-name="${ing.grade_name ?? ''}"
             data-buy-price="${ing.buy_price ?? ''}"
             data-sell-price="${ing.sell_price ?? ''}"
             data-market-price="${ing.last_sold_price ?? ''}">
            <img 
                src="${ing.icon}" 
                alt="${ing.name}" 
                class="ingredient-icon"
                onerror="this.style.display='none'"
            />
            <span class="ingredient-qty">${ing.qty_min}</span>
        </div>
    `).join('');

    const gradeClass = `grade-${recipe.grade}`;

    return `
        <a href="recipe.php?id=${recipe.id}&source=cooking" class="recipe-row">
            <div class="recipe-main">
                <img 
                    src="${recipe.icon}" 
                    alt="${recipe.name}" 
                    class="recipe-icon"
                    onerror="this.style.display='none'"
                />
                <div>
                    <div class="recipe-name ${gradeClass}">${recipe.name}</div>
                    <div class="recipe-skill">${recipe.skill_level ?? '—'}</div>
                </div>
            </div>

            <div class="recipe-exp">
                EXP <span>${recipe.exp > 0 ? recipe.exp.toLocaleString() : '—'}</span>
            </div>

            <div class="recipe-ingredients" onclick="event.preventDefault()">
                ${ingIcons}
            </div>
        </a>
    `;
}

// pageing HTML
function buildPagination(totalPages) {
    const el = document.getElementById('pagination');
    if (totalPages <= 1) { el.innerHTML = ''; return; }

    let html = '';

    // previous thing, page
    html += `<button class="page-btn" onclick="goPage(${currentPage - 1})" 
              ${currentPage === 1 ? 'disabled' : ''}>← Prev</button>`;

    // if there is lot of page number, make it shorter
    for (let i = 1; i <= totalPages; i++) {
        if (
            i === 1 || i === totalPages ||
            (i >= currentPage - 2 && i <= currentPage + 2)
        ) {
            html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" 
                      onclick="goPage(${i})">${i}</button>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            html += `<span style="color:var(--text-secondary);padding:0 0.25rem">...</span>`;
        }
    }

    // next thing, page
    html += `<button class="page-btn" onclick="goPage(${currentPage + 1})"
              ${currentPage === totalPages ? 'disabled' : ''}>Next →</button>`;

    el.innerHTML = html;
}

function goPage(p) {
    currentPage = p;
    loadRecipes();
    window.scrollTo(0, 0);
}

// Tooltip
function attachTooltips() {
    const tooltip = document.getElementById('item-tooltip');

    document.querySelectorAll('.ingredient-icon-wrap').forEach(el => {
        el.addEventListener('mouseenter', (e) => {
            // read data from data attribute
            const name        = el.dataset.itemName;
            const icon        = el.dataset.itemIcon;
            const grade       = parseInt(el.dataset.itemGrade);
            const gradeName   = el.dataset.itemGradeName;
            const buyPrice    = el.dataset.buyPrice;
            const sellPrice   = el.dataset.sellPrice;
            const marketPrice = el.dataset.marketPrice;

            // Fill tooltip
            document.getElementById('tooltip-icon').src      = icon;
            document.getElementById('tooltip-name').textContent = name;
            document.getElementById('tooltip-name').className = `tooltip-name grade-${grade}`;
            document.getElementById('tooltip-grade').textContent = gradeName;
            document.getElementById('tooltip-grade').className = `tooltip-grade grade-${grade}`;

            document.getElementById('tooltip-buyprice').innerHTML = 
                buyPrice ? `Buy Price: <span>${parseInt(buyPrice).toLocaleString()} silver</span>` : '';
            
            document.getElementById('tooltip-sellprice').innerHTML = 
                sellPrice ? `Sell Price: <span>${parseInt(sellPrice).toLocaleString()} silver</span>` : '';
            
            document.getElementById('tooltip-marketprice').innerHTML = 
                marketPrice ? `Market Price: <span>${parseInt(marketPrice).toLocaleString()} silver</span>` : '';

            tooltip.classList.add('visible');
            moveTooltip(e);
        });

        el.addEventListener('mousemove', moveTooltip);

        el.addEventListener('mouseleave', () => {
            tooltip.classList.remove('visible');
        });
    });
}

function moveTooltip(e) {
    const tooltip = document.getElementById('item-tooltip');
    const x = e.clientX + 15;
    const y = e.clientY + 15;

    // block overflow
    const maxX = window.innerWidth  - tooltip.offsetWidth  - 20;
    const maxY = window.innerHeight - tooltip.offsetHeight - 20;

    tooltip.style.left = Math.min(x, maxX) + 'px';
    tooltip.style.top  = Math.min(y, maxY) + 'px';
}

// Search and Filters
document.getElementById('recipe-search').addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        searchQuery = e.target.value.trim();
        currentPage = 1;
        loadRecipes();
    }, 400); // 400ms delay so they can't spam
});

document.getElementById('skill-filter').addEventListener('change', (e) => {
    skillFilter = e.target.value;
    currentPage = 1;
    loadRecipes();
});

document.getElementById('per-page').addEventListener('change', (e) => {
    perPage     = parseInt(e.target.value);
    currentPage = 1;
    loadRecipes();
});

// start after page loads
loadRecipes();