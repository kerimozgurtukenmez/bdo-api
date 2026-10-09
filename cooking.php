<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cooking Recipes</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        a.recipe-row {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            width: 100% !important;
            text-decoration: none !important;
            color: var(--text-primary) !important;
            background-color: var(--bg-secondary) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius) !important;
            padding: 0.75rem 1rem !important;
            gap: 1rem !important;
            box-sizing: border-box !important;
            margin-bottom: 0.5rem !important;
        }

        a.recipe-row:hover {
            border-color: var(--accent) !important;
            background-color: var(--bg-hover) !important;
        }

        .recipe-ingredients {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap !important;
            gap: 0.3rem !important;
            align-items: center !important;
            flex-shrink: 0 !important;
        }

        /* Tooltip görünür olsun */
        .item-tooltip {
            display: block !important;
            position: fixed !important;
            z-index: 9999 !important;
        }
    </style>
</head>

<body>

    <?php include 'includes/navbar.php'; ?>

    <main class="main-content">
        <!-- Page Title -->
        <div class="page-header">
            <h1 class="page-title">Cooking Recipes</h1>
            <p class="page-subtitle">All cooking recipes in Black Desert Online</p>
        </div>

        <!-- Filter Tools -->
        <div class="toolbar">
            <!-- Search -->
            <div class="toolbar-search">
                <span class="search-icon">
                    🔍
                </span>
                <input
                    type="text"
                    id="recipe-search"
                    class="input"
                    placeholder="Search recipes..."
                    autocomplete="off" />
            </div>

            <!-- Skill Level Filter -->
            <select id="skill-filter" class="input" style="width:160px">
                <option value="">All</option>
                <option value="Beginner">Beginner</option>
                <option value="Apprentice">Apprentice</option>
                <option value="Skilled">Skilled</option>
                <option value="Professional">Professional</option>
                <option value="Artisan">Artisan</option>
                <option value="Master">Master</option>
                <option value="Guru">Guru</option>
            </select>

            <!-- register per page -->
            <select id="per-page" class="input" style="width:130px">
                <option value="20">20 per page</option>
                <option value="50" selected>50 per page</option>
                <option value="100">100 per page</option>
            </select>
        </div>

        <!-- Recipe List -->
        <div class="recipe-list" id="recipe-list" style="display:flex; flex-direction:column; gap:0.5rem;">
            <div class="loading">Loading Recipes...</div>
        </div>

        <!-- Pageing -->
        <div class="pagination" id="pagination"></div>

    </main>

    <!-- Item Tooltip -->
    <div class="item-tooltip" id="item-tooltip">
        <div class="tooltip-header">
            <img src="" alt="" class="tooltip-icon" id="tooltip-icon">
            <div>
                <div class="tooltip-name" id="tooltip-name"></div>
                <div class="tooltip-grade" id="tooltip-grade"></div>
            </div>
        </div>
        <div class="tooltip-body">
            <div class="tooltip-row" id="tooltip-weight"></div>
            <div class="tooltip-row" id="tooltip-buyprice"></div>
            <div class="tooltip-row" id="tooltip-sellprice"></div>
            <div class="tooltip-row" id="tooltip-marketprice"></div>
            <div class="tooltip-desc" id="tooltip-desc"></div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script src="js/main.js"></script>
    <script src="js/cooking.js"></script>
</body>

</html>