jQuery(document).ready(function ($) {
    try {
        //console.log("WCPF Debug: Script initialized");
        //console.log("WCPF Debug: wcpf_params =", wcpf_params);

       // Kiểm tra wcpf_params ngay khi khởi tạo
        if (typeof wcpf_params === 'undefined' || !wcpf_params) {
            //console.error("WCPF Error: wcpf_params is undefined or null");
            return;
        }
        /* if (!wcpf_params.ajax_url || !wcpf_params.nonce) {
            //console.error("WCPF Error: Missing required wcpf_params properties", {
                ajax_url: wcpf_params?.ajax_url,
                nonce: wcpf_params?.nonce
            });
            return;
        } */
        /* if (!wcpf_params.category_mode) {
            console.warn("WCPF Warning: wcpf_params.category_mode is not set, defaulting to 'category_filter'");
            wcpf_params.category_mode = 'category_filter';
        } 
        //console.log("WCPF Debug: Script initialized, wcpf_params =", {
            category_mode: wcpf_params.category_mode,
            apply_filter_behavior: wcpf_params.apply_filter_behavior
        }); */

        function showLoadingOverlay() {
            $(".wcpf-loading-overlay").remove();
            const $overlay = $('<div class="wcpf-loading-overlay"></div>');
            if (wcpf_params && wcpf_params.loading_image_url) {
                $overlay.append('<img src="' + wcpf_params.loading_image_url + '" class="wcpf-loading-image" alt="' + (wcpf_params.i18n?.loading || 'Loading') + '">');
            } else {
                $overlay.append('<div class="wcpf-spinner"></div>');
            }
            $("body").append($overlay);
            $overlay.show();
            updateOverlayHeight();
        }

        function hideLoadingOverlay() {
            $(".wcpf-loading-overlay").hide();
        }

        function updateOverlayHeight() {
            const $overlay = $(".wcpf-loading-overlay");
            if ($overlay.length) {
                const height = window.innerHeight;
                $overlay.css("height", height + "px");
            }
        }

        updateOverlayHeight();
        $(window).on("resize orientationchange", debounce(updateOverlayHeight, 100));

        function debounce(func, wait) {
            let timeout;
            return function (...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    clearTimeout(timeout);
                    func.apply(this, args);
                }, wait);
            };
        }

        function adjustFilterGrid() {
            $(".wcpf-filter-menu, .wcpf-filter-section").each(function () {
                const $section = $(this);
                if (!$section.length || !$.contains(document, $section[0])) return;
                const taxonomy = $section.attr('data-taxonomy');
                if (taxonomy === "price" || taxonomy === "stock_status") return;
                const $grid = $section.find(".wcpf-filter-grid, .wcpf-mobile-filter-grid");
                if (!$grid.length) return;
                const labelCount = $grid.find(".wcpf-filter-label").length;
                $grid.removeClass("wcpf-h-2 wcpf-h-3");
                if (labelCount <= 2) {
                    $grid.addClass("wcpf-h-2");
                } else {
                    $grid.addClass("wcpf-h-3");
                }
            });
        }

        function updateSelectedCount(taxonomy) {
            try {
                if (!taxonomy || taxonomy === "undefined") {
                    //console.warn("WCPF Warning: Invalid taxonomy in updateSelectedCount");
                    return;
                }

                let selectedTerms = new Set();

                // Xử lý khi taxonomy không tồn tại trong DOM
                const $toggle = $(`.wcpf-filter-toggle[data-taxonomy="${taxonomy}"]`);
                if (!$toggle.length) {
                    //console.log(`WCPF Info: Filter toggle for ${taxonomy} not found (may be inactive)`);
                    return; // Thoát mà không báo lỗi
                }

                if (taxonomy === "price") {
                    if ($(".wcpf-price-range.selected").length > 0) {
                        selectedTerms.add("price");
                    }
                } else {
                    $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"].selected`).each(function() {
                        const term = $(this).attr("data-term") || "";
                        if (term) selectedTerms.add(term);
                    });
                }

                selectedTerms.delete("");
                const count = selectedTerms.size;

                $toggle.find(".wcpf-selected-count").remove();
                if (count > 0) {
                    $toggle.append(`<span class="wcpf-selected-count">${count}</span>`);
                }
            } catch (err) {
                //console.error("WCPF updateSelectedCount Error:", err);
            }
        }

        function syncSelectedFilters($trigger = null, priceData = null) {
            try {
                if ($trigger && $trigger.length) {
                    const taxonomy = $trigger.attr("data-taxonomy") || "";
                    const term = $trigger.attr("data-term") || "";
                    if (!taxonomy || !term || taxonomy === "undefined" || term === "undefined") {
                        //console.warn("WCPF Warning: Invalid taxonomy or term in syncSelectedFilters");
                        return;
                    }
                    if (taxonomy === "price") {
                        $(".wcpf-price-range").removeClass("selected");
                        if (priceData && priceData.minPrice) {
                            $(`.wcpf-price-range[data-min-price="${priceData.minPrice}"][data-max-price="${priceData.maxPrice || ''}"]`).addClass("selected");
                        }
                    } else {
                        if (wcpf_params?.single_select && wcpf_params.single_select[taxonomy] == 1 && $trigger.hasClass("selected")) {
                            $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"]`).not($trigger).removeClass("selected")
                                .find("input[type='checkbox']").prop("checked", false);
                        }
                        $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"][data-term="${term}"]`).toggleClass("selected", $trigger.hasClass("selected"));
                        $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"][data-term="${term}"] input[type='checkbox']`).prop("checked", $trigger.hasClass("selected"));
                    }
                }

                $(".wcpf-filter-label").each(function () {
                    const $label = $(this);
                    if (!$label.length) return;
                    const taxonomy = $label.attr("data-taxonomy") || "";
                    const term = $label.attr("data-term") || "";
                    if (!taxonomy || !term || taxonomy === "undefined" || term === "undefined") return;
                    if (!$trigger || taxonomy !== $trigger.attr("data-taxonomy") || term !== $trigger.attr("data-term")) {
                        const isSelected = $label.hasClass("selected");
                        $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"][data-term="${term}"]`).toggleClass("selected", isSelected);
                        $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"][data-term="${term}"] input[type='checkbox']`).prop("checked", isSelected);
                    }
                });

                $(".wcpf-filter-toggle").each(function () {
                    const taxonomy = $(this).attr("data-taxonomy") || "";
                    if (taxonomy) {
                        updateSelectedCount(taxonomy);
                    }
                });

                const tags = [];
                const seen = new Set();
                if (priceData && priceData.minPrice && priceData.displayText) {
                    const { minPrice, maxPrice, displayText } = priceData;
                    const cleanDisplayText = displayText.trim().replace(/\s+/g, ' ').replace(/(.+?)\s+\1/, '$1');
                    const tagKey = `price:${minPrice}-${maxPrice || 'max'}`;
                    if (!seen.has(tagKey)) {
                        seen.add(tagKey);
                        tags.push({ taxonomy: "price", term: `price-${minPrice}-${maxPrice || 'max'}`, name: cleanDisplayText });
                    }
                } else if ($(".wcpf-price-range.selected").length) {
                    const $priceRange = $(".wcpf-price-range.selected").first();
                    const min = $priceRange.attr("data-min-price") || "";
                    const max = $priceRange.attr("data-max-price") || "max";
                    const tagKey = `price:${min}-${max}`;
                    if (!seen.has(tagKey)) {
                        seen.add(tagKey);
                        let text = $priceRange.clone().children(".wcpf-term-count").remove().end().text().trim().replace(/\s+/g, ' ');
                        text = text.replace(/(.+?)\s+\1/, '$1');
                        if (!text) {
                            text = `${(min/1000000).toFixed(1)} - ${(max === "max" ? "max" : (max/1000000).toFixed(1))} triệu`;
                        }
                        tags.push({ taxonomy: "price", term: `price-${min}-${max}`, name: text });
                    }
                } else {
                    $(".wcpf-filter-toggle[data-taxonomy='price']").find(".wcpf-selected-count").remove();
                }

                $(".wcpf-filter-label.selected:not(.wcpf-price-range)").each(function () {
                    const $label = $(this);
                    const taxonomy = $label.attr("data-taxonomy") || "";
                    const term = $label.attr("data-term") || "";
                    if (!taxonomy || !term || taxonomy === "undefined" || term === "undefined") return;
                    const tagKey = `${taxonomy}:${term}`;
                    if (!seen.has(tagKey)) {
                        seen.add(tagKey);
                        let tagText = $label.clone().children(".wcpf-term-count").remove().end().text().trim().replace(/\s+/g, ' ');
                        tags.push({ taxonomy, term, name: tagText });
                    }
                });
            } catch (err) {
                //console.error("WCPF syncSelectedFilters Error:", err);
            }
        }

		function handleRemoveFilter(taxonomy, term) {
			try {
				if (!taxonomy || !term || taxonomy === "undefined" || term === "undefined") {
					console.warn("WCPF Warning: Invalid taxonomy or term in handleRemoveFilter", { taxonomy, term });
					return;
				}
				if (typeof wcpf_params === "undefined") {
					console.warn("WCPF Warning: wcpf_params is undefined in handleRemoveFilter");
					return;
				}
				if (typeof $ === "undefined") {
					console.warn("WCPF Warning: jQuery is undefined in handleRemoveFilter");
					return;
				}

				const isBrandPage = wcpf_params.taxonomy === "product_brand";
				const brandBase = wcpf_params.brand_base || "brand";
				const basePath = isBrandPage ? `${brandBase}/${wcpf_params.category || ''}` : wcpf_params.category || '';

				// Hàm lấy danh sách terms hợp lệ
				function getValidTerms(tax) {
					const terms = new Set();
					$(`.wcpf-filter-label[data-taxonomy="${tax}"]`).each(function () {
						const t = $(this).attr("data-term") || "";
						if (t && t !== "undefined") {
							terms.add(t);
						}
					});
					return terms;
				}

				// Thu thập các bộ lọc còn lại
				const filtersObj = {};
				const filterSet = new Set();

				// Cập nhật giao diện trước khi thu thập bộ lọc
				const $filterLabel = $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"][data-term="${term}"]`);
				if ($filterLabel.length) {
					$filterLabel.removeClass("selected");
					$filterLabel.find("input[type='checkbox']").prop("checked", false);
					if (typeof syncSelectedFilters === "function") {
						syncSelectedFilters(null);
					}
					if (typeof updateSelectedCount === "function") {
						updateSelectedCount(taxonomy);
					}
				}

				// Thu thập các bộ lọc đã chọn, bỏ qua term được xóa
				$(".wcpf-filter-label.selected").each(function () {
					const $label = $(this);
					const t = $label.attr("data-term") || "";
					const tax = $label.attr("data-taxonomy") || "";
					if (!t || t === "undefined" || !tax || tax === "undefined") return;
					// Bỏ qua product_cat trong chế độ category_text_link
					if (tax === "product_cat" && wcpf_params.category_mode === "category_text_link") return;
					// Bỏ qua term đang được xóa
					if (tax === taxonomy && t === term) return;
					const key = tax === "product_brand" || tax === "stock_status" ? tax : tax.replace("pa_", "");
					const filterKey = `${tax}:${t}`;
					if (!filterSet.has(filterKey)) {
						filterSet.add(filterKey);
						filtersObj[key] = filtersObj[key] || [];
						filtersObj[key].push(t);
					}
				});

				// Phân tích bộ lọc từ URL để bổ sung, bỏ qua term được xóa
				const path = window.location.pathname;
				const filterMatch = path.match(/filters\/(.+)/);
				if (filterMatch && filterMatch[1]) {
					const filterSegments = filterMatch[1].split("/").filter(segment => segment);
					filterSegments.forEach(segment => {
						const match = segment.match(/^([^-]+)-(.*)$/);
						if (!match) return;
						const taxonomyKey = match[1];
						const termsString = match[2];
						const filterTaxonomy = taxonomyKey === "stock_status" || taxonomyKey === "product_brand" ? taxonomyKey : "pa_" + taxonomyKey;
						// Bỏ qua product_cat trong chế độ category_text_link
						if (filterTaxonomy === "product_cat" && wcpf_params.category_mode === "category_text_link") return;
						// Bỏ qua term đang được xóa
						if (filterTaxonomy === taxonomy && termsString.includes(term)) return;
						const validTerms = getValidTerms(filterTaxonomy);

						// Tách terms cẩn thận
						const terms = [];
						let currentTerm = "";
						termsString.split("-").forEach((part, index, arr) => {
							currentTerm += (currentTerm ? "-" : "") + part;
							if (validTerms.has(currentTerm) || index === arr.length - 1) {
								if (validTerms.has(currentTerm)) {
									terms.push(currentTerm);
								}
								currentTerm = "";
							}
						});

						terms.forEach(t => {
							if (!(taxonomy === filterTaxonomy && t === term)) {
								const key = filterTaxonomy === "product_brand" || filterTaxonomy === "stock_status" ? filterTaxonomy : taxonomyKey;
								const filterKey = `${filterTaxonomy}:${t}`;
								if (!filterSet.has(filterKey)) {
									filterSet.add(filterKey);
									filtersObj[key] = filtersObj[key] || [];
									filtersObj[key].push(t);
								}
							}
						});
					});
				}

				// Xử lý khoảng giá
				const params = new URLSearchParams(window.location.search);
				const minPrice = taxonomy !== "price" ? params.get("min_price") || "" : "";
				const maxPrice = taxonomy !== "price" ? params.get("max_price") || "" : "";

				// Thu thập tham số query
				let searchTerm = params.get("s") || "";
				let sortBy = params.get("sort_by") || "";
				if (taxonomy === "search" && term === searchTerm) {
					searchTerm = "";
				} else if (taxonomy === "sort_by" && term === sortBy) {
					sortBy = "";
				}

				// Xây dựng query string
				let queryString = "";
				const queryParams = [];
				if (minPrice) queryParams.push(`min_price=${encodeURIComponent(minPrice)}`);
				if (maxPrice) queryParams.push(`max_price=${encodeURIComponent(maxPrice)}`);
				if (searchTerm) queryParams.push(`s=${encodeURIComponent(searchTerm)}`);
				if (sortBy) queryParams.push(`sort_by=${encodeURIComponent(sortBy)}`);
				if (searchTerm) {
					queryParams.push(`post_type=product`);
					queryParams.push(`wc_query=product_query`);
				}
				if (queryParams.length) {
					queryString = `?${queryParams.join("&")}`;
				}

				// Xây dựng URL mới
				const filterParts = [];
				for (const [key, terms] of Object.entries(filtersObj)) {
					if (terms && terms.length > 0) {
						filterParts.push(`${key}-${terms.join("-")}`);
					}
				}
				const newUrl = `${wcpf_params.home_url || ''}/${basePath}/${filterParts.length ? "filters/" + filterParts.join("/") : ""}${queryString}`;
				console.log("WCPF Debug: handleRemoveFilter", { taxonomy, term, filtersObj, newUrl });

				// Chuyển hướng
				if (typeof showLoadingOverlay === "function") {
					showLoadingOverlay();
				}
				window.location.href = newUrl;
			} catch (err) {
				if (typeof hideLoadingOverlay === "function") {
					hideLoadingOverlay();
				}
				console.error("WCPF handleRemoveFilter Error:", {
					message: err.message || "Unknown error",
					stack: err.stack || "No stack trace",
					taxonomy,
					term
				});
			}
		}

		function handleBrandTagClick(taxonomy, term) {
			try {
				if (!taxonomy || !term || taxonomy === "undefined" || term === "undefined") {
					return;
				}
				const isBrandPage = wcpf_params?.taxonomy === "product_brand";
				const brandBase = wcpf_params?.brand_base || "brand";
				const basePath = isBrandPage ? `${brandBase}/${wcpf_params?.category || ''}` : wcpf_params?.category || '';
				const $filterLabel = $(`.wcpf-filter-label[data-taxonomy="${taxonomy}"][data-term="${term}"]`);
				if (!$filterLabel.length) {
					return;
				}
				$filterLabel.toggleClass("selected");
				syncSelectedFilters($filterLabel);
				updateSelectedCount(taxonomy);
				const filtersObj = {};
				const filterSet = new Set();
				$(".wcpf-filter-label.selected:not(.wcpf-price-range)").each(function () {
					const $label = $(this);
					const t = $label.attr("data-taxonomy") || "";
					const n = $label.attr("data-term") || "";
					if (!t || !n || t === "undefined" || n === "undefined") return;
					const key = t === "product_brand" || t === "stock_status" ? t : t.replace("pa_", "");
					const filterKey = `${key}-${n}`;
					if (!filterSet.has(filterKey)) {
						filterSet.add(filterKey);
						filtersObj[key] = filtersObj[key] || [];
						filtersObj[key].push(n);
					}
				});
				const $priceRange = $(".wcpf-price-range.selected");
				const minPrice = $priceRange.length ? $priceRange.attr("data-min-price") || "" : "";
				const maxPrice = $priceRange.length ? $priceRange.attr("data-max-price") || "" : "";

				// Thu thập các tham số hiện tại
				const params = new URLSearchParams(window.location.search);
				const searchTerm = params.get("s") || "";
				const sortBy = params.get("sort_by") || "";

				// Xây dựng query string
				let queryString = "";
				const queryParams = [];
				if (minPrice) queryParams.push(`min_price=${encodeURIComponent(minPrice)}`);
				if (maxPrice) queryParams.push(`max_price=${encodeURIComponent(maxPrice)}`);
				if (searchTerm) queryParams.push(`s=${encodeURIComponent(searchTerm)}`);
				if (sortBy) queryParams.push(`sort_by=${encodeURIComponent(sortBy)}`);
				if (searchTerm) {
					queryParams.push(`post_type=product`);
					queryParams.push(`wc_query=product_query`);
				}
				if (queryParams.length) {
					queryString = `?${queryParams.join("&")}`;
				}

				if (!Object.keys(filtersObj).length && !minPrice && !maxPrice) {
					showLoadingOverlay();
					window.location.href = `${wcpf_params?.home_url || ''}/${basePath}/${queryString}`;
					return;
				}
				const filterParts = [];
				for (const [key, terms] of Object.entries(filtersObj)) {
					filterParts.push(`${key}-${terms.join("-")}`);
				}
				const newUrl = `${wcpf_params?.home_url || ''}/${basePath}/${filterParts.length ? "filters/" + filterParts.join("/") : ""}${queryString}`;
				showLoadingOverlay();
				window.location.href = newUrl;
			} catch (err) {
				hideLoadingOverlay();
				//console.error("WCPF handleBrandTagClick Error:", err);
			}
		}

        function initializeFilters() {
            try {
                // Kiểm tra nút .wcpf-apply-filters chỉ khi cần cho apply_button
				if (wcpf_params?.apply_filter_behavior === 'apply_button' && !$(".wcpf-apply-filters").length) {
					//console.warn("WCPF Warning: .wcpf-apply-filters element not found (required for apply_button behavior)");
				}
                const path = window.location.pathname;
                const filterMatch = path.match(/filters\/(.+)/);
                if (filterMatch) {
                    const filterSegments = filterMatch[1].split("/").filter(segment => segment);
                    filterSegments.forEach(segment => {
                        if (segment.startsWith("product_brand-")) {
                            const terms = segment.replace("product_brand-", "").split("-").filter(term => term);
                            terms.forEach(term => {
                                const $element = $(`.wcpf-filter-label[data-taxonomy="product_brand"][data-term="${term}"]`);
                                if ($element.length) $element.addClass("selected");
                            });
                        } else if (segment.startsWith("stock_status-")) {
                            const terms = segment.replace("stock_status-", "").split("-").filter(term => term);
                            terms.forEach(term => {
                                const $element = $(`.wcpf-filter-label[data-taxonomy="stock_status"][data-term="${term}"]`);
                                if ($element.length) $element.addClass("selected");
                            });
                        } else {
                            const parts = segment.split("-");
                            if (parts.length < 2) return;
                            const taxonomyKey = parts[0];
                            const filterTaxonomy = taxonomyKey === "stock_status" || taxonomyKey === "product_brand" ? taxonomyKey : "pa_" + taxonomyKey;
                            const terms = parts.slice(1).filter(term => term);
                            terms.forEach(term => {
                                const $element = $(`.wcpf-filter-label[data-taxonomy="${filterTaxonomy}"][data-term="${term}"]`);
                                if ($element.length) $element.addClass("selected");
                            });
                        }
                    });
                }

                const params = new URLSearchParams(window.location.search);
                const minPriceRaw = params.get("min_price") || "";
                const maxPrice = params.get("max_price") || "";
                const minPrice = minPriceRaw === "" && maxPrice ? "0" : minPriceRaw;

                if (minPrice || maxPrice) {
                    $(".wcpf-price-range").removeClass("selected");
                    const $priceRange = $(`.wcpf-price-range[data-min-price="${minPrice}"][data-max-price="${maxPrice || ''}"]`);
                    if ($priceRange.length) {
                        $priceRange.addClass("selected");
                        const displayText = $priceRange.clone().children(".wcpf-term-count").remove().end().text().trim().replace(/\s+/g, ' ').replace(/(.+?)\s+\1/, '$1') || 
                            `${(minPrice/1000000).toFixed(1)} - ${(maxPrice ? (maxPrice/1000000).toFixed(1) : "max")} triệu`;
                        syncSelectedFilters(null, { minPrice, maxPrice, displayText });
                        updateSelectedCount("price");
                    } else {
                        syncSelectedFilters(null, { minPrice: "", maxPrice: "", displayText: "" });
                        updateSelectedCount("price");
                    }
                } else {
                    $(".wcpf-price-range").removeClass("selected");
                    $(".wcpf-filter-toggle[data-taxonomy='price'], .wcpf-price-filter[data-filter='price']").find(".wcpf-selected-count").remove();
                    syncSelectedFilters(null, { minPrice: "", maxPrice: "", displayText: "" });
                    updateSelectedCount("price");
                }

                $(".wcpf-filter-toggle").each(function () {
                    const taxonomy = $(this).attr("data-taxonomy") || "";
                    if (taxonomy) {
                        updateSelectedCount(taxonomy);
                    }
                });
                syncSelectedFilters();

                if (wcpf_params?.apply_filter_behavior === 'apply_button') {
                    $(".wcpf-apply-filters")
                        .removeClass("loading")
                        .addClass("wcpf-loaded")
                        .prop("disabled", false);
                }
            } catch (err) {
                //console.error("WCPF initializeFilters Error:", err);
                const $applyButton = $(".wcpf-apply-filters");
                if ($applyButton.length) {
                    $applyButton
                        .removeClass("loading")
                        .addClass("wcpf-loaded")
                        .html(wcpf_params?.apply_button_template || 'Áp dụng')
                        .prop("disabled", wcpf_params?.apply_filter_behavior === 'apply_button' ? false : true);
                }
            }
        }

        function showMobileFilterMenu() {
            try {
                const $mobileMenu = $(".wcpf-mobile-filter-menu");
                if (!$mobileMenu.length) {
                    //console.warn("WCPF Warning: .wcpf-mobile-filter-menu not found");
                    return;
                }
                $mobileMenu.show();
                $("body").addClass("wcpf-mobile-menu-open");
                window.scrollTo(0, 0);
                document.body.style.height = "100%";
                setTimeout(() => {
                    document.body.style.height = "";
                });
                initializeFilters();
                syncSelectedFilters();
                adjustActionButtons();
                bindFilterEvents($mobileMenu);
            } catch (err) {
                //console.error("WCPF showMobileFilterMenu Error:", err);
            }
        }

        function hideMobileFilterMenu() {
            const $mobileMenu = $(".wcpf-mobile-filter-menu");
            if ($mobileMenu.length) {
                $mobileMenu.hide();
                $("body").removeClass("wcpf-mobile-menu-open");
            }
        }

        function adjustActionButtons() {
            try {
                const $buttons = $(".wcpf-action-buttons");
                const $mobileMenu = $(".wcpf-mobile-filter-menu");
                if ($buttons.length && $mobileMenu.length) {
                    const height = $buttons.outerHeight();
                    $mobileMenu.css("padding-bottom", height + "px");
                }
            } catch (err) {
                //console.error("WCPF adjustActionButtons Error:", err);
            }
        }

		function bindFilterEvents($container) {
			try {
				if (!$container || !$container.length) {
					console.warn("WCPF Warning: Invalid container in bindFilterEvents");
					return;
				}
				let pendingFilterChanges = false;
				let filterChangeTimeout = null;

				// Phần xử lý .wcpf-filter-label giữ nguyên như phiên bản trước
				$container.find(".wcpf-filter-label:not(.wcpf-price-range)")
					.off("click")
					.on("click", function (event) {
						event.preventDefault();
						event.stopPropagation();
						event.stopImmediatePropagation();
						const $label = $(this);
						if (!$label.length) return;
						const taxonomy = $label.attr("data-taxonomy") || "";
						const term = $label.attr("data-term") || "";
						if (!taxonomy || !term || taxonomy === "undefined" || term === "undefined") {
							console.warn("WCPF Warning: Invalid taxonomy or term", { taxonomy, term });
							return;
						}

						console.log("WCPF Debug: Filter label clicked", {
							taxonomy,
							term,
							category_mode: wcpf_params?.category_mode || "undefined"
						});

						// Xử lý đặc biệt cho product_cat với category_text_link
						try {
							if (taxonomy === "product_cat" && wcpf_params?.category_mode === "category_text_link") {
								console.log("WCPF Debug: Redirecting for category_text_link", { taxonomy, term });
								const categoryUrl = $label.attr("href");
								if (!categoryUrl) {
									console.warn("WCPF Warning: href attribute not found, falling back to term-based URL", { taxonomy, term });
									return;
								}
								window.location.assign(categoryUrl);
								return;
							}
						} catch (err) {
							console.error("WCPF Error: Failed to handle category_text_link", err);
							return;
						}

						// Xử lý product_cat (không phải category_text_link)
						if (taxonomy === "product_cat" && wcpf_params?.apply_filter_behavior === 'immediate') {
							const isBrandPage = wcpf_params?.taxonomy === "product_brand";
							const brandBase = wcpf_params?.brand_base || "brand";
							const basePath = isBrandPage ? `${brandBase}/${wcpf_params?.category || ''}` : wcpf_params?.category || '';
							const filtersObj = {};
							const filterSet = new Set();

							$(".wcpf-filter-label:not(.wcpf-price-range)").each(function () {
								const $currentLabel = $(this);
								const t = $currentLabel.attr("data-taxonomy") || "";
								const n = $currentLabel.attr("data-term") || "";
								if (!t || !n || t === "undefined" || n === "undefined") return;
								const isSelected = $currentLabel.hasClass("selected");
								const isCurrent = t === taxonomy && n === term;
								if (t === taxonomy && wcpf_params?.single_select && wcpf_params.single_select[taxonomy] == 1) {
									if (isCurrent) {
										if (!isSelected) {
											const key = t === "product_brand" || t === "stock_status" || t === "product_cat" ? t : t.replace("pa_", "");
											const filterKey = `${key}-${n}`;
											if (!filterSet.has(filterKey)) {
												filterSet.add(filterKey);
												filtersObj[key] = filtersObj[key] || [];
												filtersObj[key].push(n);
											}
										}
									}
								} else {
									if ((isCurrent && !isSelected) || (!isCurrent && isSelected)) {
										const key = t === "product_brand" || t === "stock_status" || t === "product_cat" ? t : t.replace("pa_", "");
										const filterKey = `${key}-${n}`;
										if (!filterSet.has(filterKey)) {
											filterSet.add(filterKey);
											filtersObj[key] = filtersObj[key] || [];
											filtersObj[key].push(n);
										}
									}
								}
							});

							const $priceRange = $(".wcpf-price-range.selected");
							const minPrice = $priceRange.length ? $priceRange.attr("data-min-price") || "" : "";
							const maxPrice = $priceRange.length ? $priceRange.attr("data-max-price") || "" : "";

							const params = new URLSearchParams(window.location.search);
							const searchTerm = params.get("s") || "";
							const sortBy = params.get("sort_by") || "";

							let queryString = "";
							const queryParams = [];
							if (minPrice) queryParams.push(`min_price=${encodeURIComponent(minPrice)}`);
							if (maxPrice) queryParams.push(`max_price=${encodeURIComponent(maxPrice)}`);
							if (searchTerm) queryParams.push(`s=${encodeURIComponent(searchTerm)}`);
							if (sortBy) queryParams.push(`sort_by=${encodeURIComponent(sortBy)}`);
							if (searchTerm) {
								queryParams.push(`post_type=product`);
								queryParams.push(`wc_query=product_query`);
							}
							if (queryParams.length) {
								queryString = `?${queryParams.join("&")}`;
							}

							const filterParts = [];
							for (const [key, terms] of Object.entries(filtersObj)) {
								filterParts.push(`${key}-${terms.join("-")}`);
							}
							const newUrl = `${wcpf_params?.home_url || ''}/${basePath}/${filterParts.length ? "filters/" + filterParts.join("/") : ""}${queryString}`;
							console.log("WCPF Debug: product_cat filter applied", { taxonomy, term, filtersObj, filterParts, newUrl });

							showLoadingOverlay();
							window.location.href = newUrl;
							return;
						}

						// Logic cho các bộ lọc khác
						if (wcpf_params?.single_select && wcpf_params.single_select[taxonomy] == 1) {
							$(`.wcpf-filter-label[data-taxonomy="${taxonomy}"]`).not($label).removeClass("selected")
								.find("input[type='checkbox']").prop("checked", false);
						}
						$label.toggleClass("selected");
						const $checkbox = $label.find("input[type='checkbox']");
						if ($checkbox.length) {
							$checkbox.prop("checked", $label.hasClass("selected"));
						}
						syncSelectedFilters($label);
						updateSelectedCount(taxonomy);

						pendingFilterChanges = true;
						clearTimeout(filterChangeTimeout);
						filterChangeTimeout = setTimeout(() => {
							if (pendingFilterChanges) {
								if (wcpf_params?.apply_filter_behavior === 'immediate') {
									const isBrandPage = wcpf_params?.taxonomy === "product_brand";
									const brandBase = wcpf_params?.brand_base || "brand";
									const basePath = isBrandPage ? `${brandBase}/${wcpf_params?.category || ''}` : wcpf_params?.category || '';
									const filtersObj = {};
									const filterSet = new Set();
									$(".wcpf-filter-label.selected:not(.wcpf-price-range)").each(function () {
										const $label = $(this);
										const t = $label.attr("data-taxonomy") || "";
										const n = $label.attr("data-term") || "";
										if (!t || !n || t === "undefined" || n === "undefined") return;
										if (t === "product_cat" && wcpf_params?.category_mode === "category_text_link") return;
										const key = t === "product_brand" || t === "stock_status" ? t : t.replace("pa_", "");
										const filterKey = `${key}-${n}`;
										if (!filterSet.has(filterKey)) {
											filterSet.add(filterKey);
											filtersObj[key] = filtersObj[key] || [];
											filtersObj[key].push(n);
										}
									});
									const $priceRange = $(".wcpf-price-range.selected");
									const minPrice = $priceRange.length ? $priceRange.attr("data-min-price") || "" : "";
									const maxPrice = $priceRange.length ? $priceRange.attr("data-max-price") || "" : "";

									const params = new URLSearchParams(window.location.search);
									const searchTerm = params.get("s") || "";
									const sortBy = params.get("sort_by") || "";

									let queryString = "";
									const queryParams = [];
									if (minPrice) queryParams.push(`min_price=${encodeURIComponent(minPrice)}`);
									if (maxPrice) queryParams.push(`max_price=${encodeURIComponent(maxPrice)}`);
									if (searchTerm) queryParams.push(`s=${encodeURIComponent(searchTerm)}`);
									if (sortBy) queryParams.push(`sort_by=${encodeURIComponent(sortBy)}`);
									if (searchTerm) {
										queryParams.push(`post_type=product`);
										queryParams.push(`wc_query=product_query`);
									}
									if (queryParams.length) {
										queryString = `?${queryParams.join("&")}`;
									}

									if (!Object.keys(filtersObj).length && !minPrice && !maxPrice) {
										showLoadingOverlay();
										window.location.href = `${wcpf_params?.home_url || ''}/${basePath}/${queryString}`;
										return;
									}
									const filterParts = [];
									for (const [key, terms] of Object.entries(filtersObj)) {
										filterParts.push(`${key}-${terms.join("-")}`);
									}
									const newUrl = `${wcpf_params?.home_url || ''}/${basePath}/${filterParts.length ? "filters/" + filterParts.join("/") : ""}${queryString}`;
									console.log("WCPF Debug: Filter applied", { taxonomy, term, filtersObj, filterParts, newUrl });
									showLoadingOverlay();
									window.location.href = newUrl;
								}
								pendingFilterChanges = false;
							}
						});
					});

				// Phần xử lý .wcpf-price-range được sửa đổi
				$container.find(".wcpf-price-range")
					.off("click")
					.on("click", function (event) {
						event.preventDefault();
						const $range = $(this);
						if (!$range.length) return;
						const minPrice = $range.attr("data-min-price") || "";
						const maxPrice = $range.attr("data-max-price") || "";
						const displayText = $range.clone().children(".wcpf-term-count").remove().end().text().trim().replace(/\s+/g, ' ').replace(/(.+?)\s+\1/, '$1') || 
							`${(minPrice/1000000).toFixed(1)} - ${(maxPrice ? (maxPrice/1000000).toString() : "max")} triệu`;
						const $checkbox = $range.find('input[type="checkbox"]');
						let isSelected = $range.hasClass("selected");

						// Cập nhật trạng thái ngay lập tức
						if (isSelected) {
							// Bỏ chọn khoảng giá
							$range.removeClass("selected");
							if ($checkbox.length) {
								$checkbox.prop("checked", false);
							}
							syncSelectedFilters(null, { minPrice: "", maxPrice: "", displayText: "" });
							updateSelectedCount("price");
							$(".wcpf-filter-toggle[data-taxonomy='price']").find(".wcpf-selected-count").remove();
						} else {
							// Chọn khoảng giá mới
							$(".wcpf-price-range").removeClass("selected").find("input[type='checkbox']").prop("checked", false);
							$range.addClass("selected");
							if ($checkbox.length) {
								$checkbox.prop("checked", true);
							}
							syncSelectedFilters(null, { minPrice, maxPrice, displayText });
							updateSelectedCount("price");
						}

						// Kích hoạt chuyển hướng ngay lập tức
						if (wcpf_params?.apply_filter_behavior === 'immediate') {
							const isBrandPage = wcpf_params?.taxonomy === "product_brand";
							const brandBase = wcpf_params?.brand_base || "brand";
							const basePath = isBrandPage ? `${brandBase}/${wcpf_params?.category || ''}` : wcpf_params?.category || '';
							const filtersObj = {};
							const filterSet = new Set();
							$(".wcpf-filter-label.selected:not(.wcpf-price-range)").each(function () {
								const $label = $(this);
								const t = $label.attr("data-taxonomy") || "";
								const n = $label.attr("data-term") || "";
								if (!t || !n || t === "undefined" || n === "undefined") return;
								if (t === "product_cat" && wcpf_params?.category_mode === "category_text_link") return;
								const key = t === "product_brand" || t === "stock_status" ? t : t.replace("pa_", "");
								const filterKey = `${key}-${n}`;
								if (!filterSet.has(filterKey)) {
									filterSet.add(filterKey);
									filtersObj[key] = filtersObj[key] || [];
									filtersObj[key].push(n);
								}
							});
							const currentMinPrice = isSelected ? "" : minPrice;
							const currentMaxPrice = isSelected ? "" : maxPrice;

							const params = new URLSearchParams(window.location.search);
							const searchTerm = params.get("s") || "";
							const sortBy = params.get("sort_by") || "";

							let queryString = "";
							const queryParams = [];
							if (currentMinPrice) queryParams.push(`min_price=${encodeURIComponent(currentMinPrice)}`);
							if (currentMaxPrice) queryParams.push(`max_price=${encodeURIComponent(currentMaxPrice)}`);
							if (searchTerm) queryParams.push(`s=${encodeURIComponent(searchTerm)}`);
							if (sortBy) queryParams.push(`sort_by=${encodeURIComponent(sortBy)}`);
							if (searchTerm) {
								queryParams.push(`post_type=product`);
								queryParams.push(`wc_query=product_query`);
							}
							if (queryParams.length) {
								queryString = `?${queryParams.join("&")}`;
							}

							if (!Object.keys(filtersObj).length && !currentMinPrice && !currentMaxPrice) {
								showLoadingOverlay();
								window.location.href = `${wcpf_params?.home_url || ''}/${basePath}/${queryString}`;
								return;
							}
							const filterParts = [];
							for (const [key, terms] of Object.entries(filtersObj)) {
								filterParts.push(`${key}-${terms.join("-")}`);
							}
							const newUrl = `${wcpf_params?.home_url || ''}/${basePath}/${filterParts.length ? "filters/" + filterParts.join("/") : ""}${queryString}`;
							console.log("WCPF Debug: Price range applied", { minPrice, maxPrice, filtersObj, filterParts, newUrl });
							showLoadingOverlay();
							window.location.href = newUrl;
						}
					});

			} catch (err) {
				console.error("WCPF bindFilterEvents Error:", err);
			}
		}

        $(window).on("resize", debounce(adjustActionButtons, 100));

        $(".wcpf-filter-button").on("click", function (event) {
            try {
                event.preventDefault();
                showMobileFilterMenu();
            } catch (err) {
                //console.error("WCPF Filter Button Error:", err);
            }
        });

        $(".wcpf-brand-tag").on("click", function (event) {
            try {
                event.preventDefault();
                if ($(this).hasClass("wcpf-brand-disabled")) return;
                const taxonomy = $(this).attr("data-taxonomy") || "";
                const term = $(this).attr("data-term") || "";
                if (!taxonomy || !term || taxonomy === "undefined" || term === "undefined") return;
                handleBrandTagClick(taxonomy, term);
            } catch (err) {
                hideLoadingOverlay();
                //console.error("WCPF Brand Tag Error:", err);
            }
        });

        $(".wcpf-filter-toggle").on("click", function (event) {
            try {
                event.preventDefault();
                const $toggle = $(this);
                const taxonomy = $toggle.attr("data-taxonomy") || "";
                if (!taxonomy) {
                    //console.warn("WCPF Warning: Missing taxonomy in filter toggle");
                    return;
                }
                if ($(window).width() <= 768) {
                    showMobileFilterMenu();
                } else {
                    $(".wcpf-filter-toggle").not($toggle).removeClass("active");
                    const $menu = $(`.wcpf-filter-menu[data-taxonomy="${taxonomy}"]`);
                    if (!$menu.length) {
                        //console.warn(`WCPF Warning: Filter menu for taxonomy ${taxonomy} not found`);
                        return;
                    }
                    const isVisible = $menu.is(":visible");
                    $(".wcpf-filter-toggle").not($toggle).removeClass("active");
                    $(".wcpf-filter-menu").not($menu).hide();
                    if (isVisible) {
                        $menu.hide();
                        $toggle.removeClass("active");
                    } else {
                        $menu.show();
                        $toggle.addClass("active");
                    }
                    adjustFilterGrid();
                }
            } catch (err) {
                //console.error("WCPF Filter Toggle Error:", err);
            }
        });

        $(".wcpf-close-menu").on("click", function (event) {
            try {
                event.preventDefault();
                initializeFilters();
                syncSelectedFilters();
                hideMobileFilterMenu();
            } catch (err) {
                //console.error("WCPF Close Menu Error:", err);
            }
        });

        $(document).on("click", function (event) {
            try {
                if ($(window).width() > 768 && !$(event.target).closest(".wcpf-filter-group").length) {
                    $(".wcpf-filter-menu").hide();
                    $(".wcpf-filter-toggle").removeClass("active");
                }
            } catch (err) {
                //console.error("WCPF Document Click Error:", err);
            }
        });

		$(document).on("click touchstart touchend", ".wcpf-apply-filters", function (event) {
			try {
				event.preventDefault();
				event.stopPropagation();
				/* console.log("WCPF Debug: Apply button triggered", { 
					eventType: event.type, 
					isMobile: /Mobi|Android/i.test(navigator.userAgent) || window.innerWidth <= 768 
				}); */

				if ($(this).prop("disabled")) {
					//console.log("WCPF Debug: Apply button disabled, skipping...");
					return;
				}

				if (wcpf_params?.apply_filter_behavior === 'apply_button') {
					const isBrandPage = wcpf_params?.taxonomy === "product_brand";
					const brandBase = wcpf_params?.brand_base || "brand";
					const basePath = isBrandPage ? `${brandBase}/${wcpf_params?.category || ''}` : wcpf_params?.category || '';
					const filtersObj = {};
					const filterSet = new Set();

					// Thu thập các bộ lọc đã chọn
					$(".wcpf-filter-label.selected:not(.wcpf-price-range)").each(function () {
						const $label = $(this);
						const t = $label.attr("data-taxonomy") || "";
						const n = $label.attr("data-term") || "";
						if (!t || !n || t === "undefined" || n === "undefined") return;
						// Bỏ qua product_cat ở chế độ category_text_link
						if (t === "product_cat" && wcpf_params?.category_mode === "category_text_link") {
							//console.log("WCPF Debug: Skipping product_cat in category_text_link mode", { taxonomy: t, term: n });
							return;
						}
						const key = t === "product_brand" || t === "stock_status" ? t : t.replace("pa_", "");
						const filterKey = `${key}-${n}`;
						if (!filterSet.has(filterKey)) {
							filterSet.add(filterKey);
							filtersObj[key] = filtersObj[key] || [];
							filtersObj[key].push(n);
						}
					});

					// Thu thập khoảng giá
					const $priceRange = $(".wcpf-price-range.selected");
					const minPrice = $priceRange.length ? $priceRange.attr("data-min-price") || "" : "";
					const maxPrice = $priceRange.length ? $priceRange.attr("data-max-price") || "" : "";

					// Thu thập từ khóa tìm kiếm
					let searchTerm = "";
					const $searchInput = $(".wcpf-search-form input[name='s']:visible, input.wcpf-search-input:visible, input[type='search'][name='s']:visible").filter(function() {
						return $(this).closest(".wcpf-filter-group.search").is(":visible");
					});
					if ($searchInput.length) {
						searchTerm = $searchInput.first().val().trim();
						/* console.log("WCPF Debug: Search input found", { 
							searchTerm, 
							inputCount: $searchInput.length, 
							inputVal: $searchInput.first().val(), 
							selector: $searchInput.first()[0]?.outerHTML || "No input" 
						}); */
					} else {
						//console.log("WCPF Debug: Search input NOT found, checking URL...");
						const params = new URLSearchParams(window.location.search);
						searchTerm = params.get("s") || "";
					}

					// Thu thập sort_by
					const params = new URLSearchParams(window.location.search);
					const sortBy = params.get("sort_by") || "";

					// Cho phép chuyển hướng nếu có bất kỳ bộ lọc hoặc searchTerm
					if (
						Object.keys(filtersObj).length === 0 &&
						!minPrice &&
						!maxPrice &&
						!searchTerm &&
						!sortBy
					) {
						/* console.log("WCPF Debug: No filters or search term, keeping current URL", {
							filters: Object.keys(filtersObj),
							minPrice,
							maxPrice,
							searchTerm,
							sortBy
						}); */
						return;
					}

					// Xây dựng query string
					let queryString = "";
					const queryParams = [];
					if (minPrice) queryParams.push(`min_price=${encodeURIComponent(minPrice)}`);
					if (maxPrice) queryParams.push(`max_price=${encodeURIComponent(maxPrice)}`);
					if (searchTerm) queryParams.push(`s=${encodeURIComponent(searchTerm)}`);
					if (sortBy) queryParams.push(`sort_by=${encodeURIComponent(sortBy)}`);
					// Chỉ thêm post_type và wc_query khi có searchTerm
					if (searchTerm) {
						queryParams.push(`post_type=product`);
						queryParams.push(`wc_query=product_query`);
					}
					if (queryParams.length) {
						queryString = `?${queryParams.join("&")}`;
					}

					// Xây dựng URL mới
					const filterParts = [];
					for (const [key, terms] of Object.entries(filtersObj)) {
						filterParts.push(`${key}-${terms.join("-")}`);
					}
					const newUrl = `${wcpf_params?.home_url || ''}/${basePath}/${filterParts.length ? "filters/" + filterParts.join("/") : ""}${queryString}`;
					//console.log("WCPF Debug: Apply Filters URL", { newUrl, searchTerm, filtersObj });

					showLoadingOverlay();
					window.location.href = newUrl;
				}
			} catch (err) {
				//console.error("WCPF Apply Filters Error:", err);
				const $applyButton = $(".wcpf-apply-filters");
				if ($applyButton.length) {
					$applyButton
						.removeClass("loading")
						.addClass("wcpf-loaded")
						.html(wcpf_params?.apply_button_template || 'Áp dụng')
						.prop("disabled", wcpf_params?.apply_filter_behavior === 'apply_button' ? false : true);
				}
				hideLoadingOverlay();
			}
		});

        $(".wcpf-selected-filters").on("click", ".wcpf-remove-filter", function (event) {
            try {
                event.preventDefault();
                const taxonomy = $(this).attr("data-taxonomy") || "";
                const term = $(this).attr("data-term") || "";
                handleRemoveFilter(taxonomy, term);
            } catch (err) {
                hideLoadingOverlay();
                //console.error("WCPF Remove Filter Error:", err);
            }
        });

        $(".wcpf-reset-filters").on("click", function (event) {
            try {
                event.preventDefault();
                const isBrandPage = wcpf_params?.taxonomy === "product_brand";
                const brandBase = wcpf_params?.brand_base || "brand";
                const basePath = isBrandPage ? `${brandBase}/${wcpf_params?.category || ''}` : wcpf_params?.category || '';
                showLoadingOverlay();
                window.location.href = `${wcpf_params?.home_url || ''}/${basePath}/`;
            } catch (err) {
                hideLoadingOverlay();
                //console.error("WCPF Reset Filters Error:", err);
            }
        });

        if (typeof wcpf_params !== 'undefined' && wcpf_params.max_terms_per_attribute >= 0) {
            $('.wcpf-mobile-filter-grid, .wcpf-filter-grid').each(function() {
                const $menu = $(this);
                if (!$menu.length) return;
                const $labels = $menu.find('.wcpf-filter-label');
                const maxTerms = parseInt($menu.attr('data-max-terms')) || parseInt(wcpf_params.max_terms_per_attribute) || 0;
                if (maxTerms > 0 && $labels.length > maxTerms) {
                    $labels.slice(maxTerms).addClass('hidden');
                    const $toggleButton = $('<a href="#" class="wcpf-show-more-toggle">' + (wcpf_params.i18n?.show_more || 'Xem thêm') + '</a>');
                    $menu.append($toggleButton);
                    $toggleButton.on('click', function(e) {
                        e.preventDefault();
                        const $hiddenLabels = $menu.find('.wcpf-filter-label.hidden');
                        if ($hiddenLabels.length > 0) {
                            $hiddenLabels.removeClass('hidden');
                            $toggleButton.text(wcpf_params.i18n?.show_less || 'Thu gọn');
                        } else {
                            $labels.slice(maxTerms).addClass('hidden');
                            $toggleButton.text(wcpf_params.i18n?.show_more || 'Xem thêm');
                        }
                    });
                } 
            });
        }

		$('.wcpf-sort-select').on('change', function(e) {
			try {
				e.preventDefault();
				const sortValue = $(this).val();
				const currentUrl = new URL(window.location.href);
				const params = new URLSearchParams(currentUrl.search);

				// Kiểm tra searchTerm
				let searchTerm = params.get('s') || '';
				const $searchInput = $(".wcpf-search-form input[name='s']:visible, input.wcpf-search-input:visible, input[type='search'][name='s']:visible");
				if ($searchInput.length) {
					searchTerm = $searchInput.first().val().trim();
				}

				// Giữ các tham số hiện tại
				if (sortValue && sortValue !== 'menu_order') {
					params.set('sort_by', sortValue);
				} else {
					params.delete('sort_by');
				}

				// Chỉ thêm post_type và wc_query khi có searchTerm
				if (searchTerm) {
					params.set('post_type', 'product');
					params.set('wc_query', 'product_query');
				} else {
					params.delete('post_type');
					params.delete('wc_query');
				}

				currentUrl.search = params.toString();
				//console.log("WCPF Debug: Sort select changed", { sortValue, searchTerm, newUrl: currentUrl.toString() });

				showLoadingOverlay();
				window.location.href = currentUrl.toString();
			} catch (err) {
				//console.error("WCPF Sort Select Error:", err);
				hideLoadingOverlay();
			}
		});

        initializeFilters();
        syncSelectedFilters();
        adjustFilterGrid();
        $(".wcpf-filter-toggle").each(function () {
            const taxonomy = $(this).attr("data-taxonomy") || "";
            if (taxonomy) {
                updateSelectedCount(taxonomy);
            }
        });
		// Gắn sự kiện chỉ cho container tồn tại
		const containers = [
			".wcpf-filter-menu",
			".wcpf-mobile-filter-menu",
			".wcpf-filter-wrapper"
		];
		containers.forEach(selector => {
			const $container = $(selector);
			if ($container.length) {
				bindFilterEvents($container);
			} else if (selector !== ".wcpf-filter-menu") {
				//console.log(`WCPF Info: Container ${selector} not found, skipping bindFilterEvents`);
			}
		});
    } catch (err) {
        //console.error("WCPF Main Error:", err);
        const $applyButton = $(".wcpf-apply-filters");
        if ($applyButton.length) {
            $applyButton
                .removeClass("loading")
                .addClass("wcpf-loaded")
                .html(wcpf_params?.apply_button_template || 'Áp dụng')
                .prop("disabled", wcpf_params?.apply_filter_behavior === 'apply_button' ? false : true);
        }
    }
    function initCategoryToggle() {
        try {
            $('.wcpf-toggle-icon').each(function () {
                const $toggle = $(this);
                $toggle.off('click').on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const targetId = $toggle.attr('data-toggle-target');
                    const $children = $(`#${targetId}`);
                    if ($children.is(':hidden')) {
                        $children.show();
                        $toggle.removeClass('wcpf-toggle-closed').addClass('wcpf-toggle-open');
                    } else {
                        $children.hide();
                        $toggle.removeClass('wcpf-toggle-open').addClass('wcpf-toggle-closed');
                    }
                });
            });
        } catch (err) {
            //console.error('WCPF initCategoryToggle Error:', err);
        }
    }

    // Khởi tạo toggle danh mục
    initCategoryToggle();
	
});