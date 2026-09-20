/**
 * Biometric Gate admin dashboard — Tab A (User Directory) and Tab C (Live Audit Log).
 * Tab B is a conventional server-rendered settings form (see class-bg-admin-page.php) and
 * needs no JS here. Vanilla JS, no build step, talks only to this plugin's own REST routes.
 */
(function () {
	"use strict";

	if (typeof window.BiometricGateAdmin === "undefined") {
		return;
	}

	var cfg = window.BiometricGateAdmin;
	var root = document.getElementById("bg-admin-root");

	if (!root) {
		return;
	}

	function apiFetch(path, opts) {
		opts = opts || {};
		var headers = Object.assign(
			{ "X-WP-Nonce": cfg.nonce },
			opts.headers || {},
		);

		if (opts.body && !(opts.body instanceof FormData)) {
			headers["Content-Type"] = "application/json";
			opts.body = JSON.stringify(opts.body);
		}

		return fetch(
			cfg.restUrl + path,
			Object.assign({ credentials: "same-origin" }, opts, { headers: headers }),
		).then(function (response) {
			return response
				.json()
				.catch(function () {
					return {};
				})
				.then(function (json) {
					if (!response.ok) {
						var err = new Error(json.message || "Request failed");
						err.code = json.code;
						throw err;
					}
					return json;
				});
		});
	}

	function debounce(fn, wait) {
		var timer;
		return function () {
			var args = arguments;
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				fn.apply(null, args);
			}, wait);
		};
	}

	function td(text) {
		var cell = document.createElement("td");
		cell.textContent = text;
		return cell;
	}

	function showError(err) {
		window.alert((err && err.message) || "Something went wrong.");
	}

	/**
	 * PHP already applies the confidence threshold before ever writing 'success' vs 'failure'
	 * (see BG_Verification::verify_against_reference()), so this only needs to color by the
	 * status that's already been decided server-side — never re-derive pass/fail here.
	 */
	function buildStatusBadge(status, confidenceScore) {
		var labels = {
			success: "Success",
			failure: "Failure",
			cloud_bypass: "Cloud Bypass",
			timeout: "Timeout",
		};
		var colors = {
			success: "#00a32a",
			failure: "#d63638",
			cloud_bypass: "#dba617",
			timeout: "#787c82",
		};

		var label = labels[status] || status;
		if (confidenceScore !== null && confidenceScore !== undefined && confidenceScore !== "") {
			label += " (" + parseFloat(confidenceScore).toFixed(1) + "% Match)";
		}

		var badge = document.createElement("span");
		badge.className = "bg-status-badge";
		badge.textContent = label;
		badge.style.backgroundColor = colors[status] || "#787c82";
		return badge;
	}

	function humanSize(bytes) {
		if (bytes < 1024) {
			return bytes + " B";
		}
		if (bytes < 1024 * 1024) {
			return (bytes / 1024).toFixed(1) + " KB";
		}
		return (bytes / 1024 / 1024).toFixed(1) + " MB";
	}

	// ---------------------------------------------------------------------
	// Tab A: User Directory & Access Toggles
	// ---------------------------------------------------------------------

	function renderUsersTab() {
		root.innerHTML =
			'<div class="bg-admin-toolbar"><input type="search" id="bg-user-search" placeholder="Search by name or email…" class="regular-text"></div>' +
			'<table class="widefat striped"><thead><tr>' +
			"<th>Name</th><th>Email</th><th>Status</th><th>Upload ID Photo</th><th>Enabled</th><th>Actions</th>" +
			'</tr></thead><tbody id="bg-user-rows"></tbody></table>';

		document.getElementById("bg-user-search").addEventListener(
			"input",
			debounce(function (e) {
				loadUsers(e.target.value);
			}, 300),
		);

		loadUsers("");
	}

	function loadUsers(search) {
		apiFetch("/admin/users?search=" + encodeURIComponent(search))
			.then(renderUserRows)
			.catch(showError);
	}

	function renderUserRows(users) {
		var tbody = document.getElementById("bg-user-rows");
		tbody.innerHTML = "";

		users.forEach(function (u) {
			var tr = document.createElement("tr");

			var nameTd = document.createElement("td");
			var nameLink = document.createElement("a");
			nameLink.href = cfg.logsTabUrl + "&user_id=" + u.id;
			nameLink.textContent = u.name;
			nameTd.appendChild(nameLink);
			tr.appendChild(nameTd);

			tr.appendChild(td(u.email));

			var statusTd = td(
				u.locked
					? cfg.i18n.locked
					: u.has_enrollment
						? cfg.i18n.idTokenLoaded
						: cfg.i18n.idMissing,
			);
			tr.appendChild(statusTd);

			var uploadTd = document.createElement("td");
			var typeSelect = document.createElement("select");
			typeSelect.className = "bg-upload-type-select";
			var optStandard = document.createElement("option");
			optStandard.value = "standard_image";
			optStandard.textContent = "Standard Student Image";
			var optOfficial = document.createElement("option");
			optOfficial.value = "official_id";
			optOfficial.textContent = "Official ID (Passport/License)";
			typeSelect.appendChild(optStandard);
			typeSelect.appendChild(optOfficial);

			var fileInput = document.createElement("input");
			fileInput.type = "file";
			fileInput.accept = "image/jpeg,image/png";
			var uploadBtn = document.createElement("button");
			uploadBtn.type = "button";
			uploadBtn.className = "button";
			uploadBtn.textContent = "Upload";
			uploadBtn.addEventListener("click", function () {
				uploadIdPhoto(u.id, fileInput, typeSelect.value, statusTd);
			});
			uploadTd.appendChild(typeSelect);
			uploadTd.appendChild(document.createElement("br"));
			uploadTd.appendChild(fileInput);
			uploadTd.appendChild(uploadBtn);
			tr.appendChild(uploadTd);

			var enabledTd = document.createElement("td");
			var toggle = document.createElement("input");
			toggle.type = "checkbox";
			toggle.checked = !u.bypass;
			toggle.addEventListener("change", function () {
				apiFetch("/admin/toggle-gate-user", {
					method: "POST",
					body: { user_id: u.id, enabled: toggle.checked },
				}).catch(function (err) {
					toggle.checked = !toggle.checked;
					showError(err);
				});
			});
			enabledTd.appendChild(toggle);
			tr.appendChild(enabledTd);

			var actionsTd = document.createElement("td");
			var resetBtn = document.createElement("button");
			resetBtn.type = "button";
			resetBtn.className = "button";
			resetBtn.textContent = "Reset Biometrics";
			resetBtn.addEventListener("click", function () {
				if (!window.confirm(cfg.i18n.confirmReset)) {
					return;
				}
				apiFetch("/admin/reset", { method: "POST", body: { user_id: u.id } })
					.then(function () {
						loadUsers(document.getElementById("bg-user-search").value);
					})
					.catch(showError);
			});

			var exportBtn = document.createElement("button");
			exportBtn.type = "button";
			exportBtn.className = "button";
			exportBtn.textContent = "Export Student History";
			exportBtn.addEventListener("click", function () {
				exportUserHistory(u.id, exportBtn);
			});

			actionsTd.appendChild(resetBtn);
			actionsTd.appendChild(document.createTextNode(" "));
			actionsTd.appendChild(exportBtn);
			tr.appendChild(actionsTd);

			tbody.appendChild(tr);
		});
	}

	function uploadIdPhoto(userId, fileInput, uploadType, statusCell) {
		if (!fileInput.files || !fileInput.files[0]) {
			window.alert("Choose a file first.");
			return;
		}

		var formData = new FormData();
		formData.append("user_id", userId);
		formData.append("id_photo", fileInput.files[0]);
		formData.append("upload_type", uploadType || "standard_image");

		apiFetch("/admin/enroll", { method: "POST", body: formData })
			.then(function () {
				if (statusCell) {
					statusCell.textContent = cfg.i18n.idTokenLoaded;
				}
			})
			.catch(showError);
	}

	function exportUserHistory(userId, btn) {
		btn.disabled = true;
		var originalLabel = btn.textContent;

		apiFetch("/admin/export-user", {
			method: "POST",
			body: { user_id: userId },
		})
			.then(function (res) {
				pollProgress(res.progress_key, btn, originalLabel);
			})
			.catch(function (err) {
				btn.disabled = false;
				showError(err);
			});
	}

	function pollProgress(key, btn, originalLabel) {
		btn.textContent = cfg.i18n.exporting;
		var interval = window.setInterval(function () {
			apiFetch("/admin/export-progress?key=" + encodeURIComponent(key))
				.then(function (progress) {
					if ("done" === progress.status) {
						window.clearInterval(interval);
						btn.disabled = false;
						btn.textContent = originalLabel;
						var url =
							cfg.downloadBaseUrl +
							"?action=bg_download_backup&file=" +
							encodeURIComponent(progress.file) +
							"&_wpnonce=" +
							cfg.downloadNonce;
						window.location.href = url;
					}
				})
				.catch(function () {
					window.clearInterval(interval);
					btn.disabled = false;
					btn.textContent = originalLabel;
				});
		}, 1500);
	}

	// ---------------------------------------------------------------------
	// Tab C: Live Audit Log & Data Management
	// ---------------------------------------------------------------------

	var logsState = {
		search: "",
		user_id: cfg.initialUserId || 0,
		orderby: "time",
		order: "DESC",
		page: 1,
	};

	function renderLogsTab() {
		var wipeLabel = logsState.user_id
			? "Export &amp; Wipe This Student's Logs"
			: "Export &amp; Wipe Live Logs";

		root.innerHTML =
			'<div class="bg-admin-toolbar">' +
			'<input type="search" id="bg-log-search" placeholder="Search by user name…" class="regular-text">' +
			'<button type="button" class="button" id="bg-log-sort-toggle"></button>' +
			(logsState.user_id
				? '<button type="button" class="button" id="bg-log-clear-filter">Clear User Filter</button>'
				: "") +
			'<button type="button" class="button button-primary" id="bg-export-wipe">' + wipeLabel + '</button>' +
			'<span id="bg-export-wipe-status"></span>' +
			"</div>" +
			'<table class="widefat striped"><thead><tr><th>Timestamp</th><th>User</th><th>Status</th><th>Page</th></tr></thead><tbody id="bg-log-rows"></tbody></table>' +
			'<div id="bg-log-pagination" class="bg-admin-pagination"></div>' +
			"<h2>Server Document Archive</h2>" +
			'<table class="widefat striped"><thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody id="bg-backup-rows"></tbody></table>';

		document.getElementById("bg-log-search").addEventListener(
			"input",
			debounce(function (e) {
				logsState.search = e.target.value;
				logsState.page = 1;
				loadLogs();
			}, 300),
		);

		var sortBtn = document.getElementById("bg-log-sort-toggle");
		updateSortButtonLabel(sortBtn);
		sortBtn.addEventListener("click", function () {
			// Time -> Name A-Z -> Name Z-A -> back to Time, each visually distinct so it's
			// obvious the click actually did something (unlike a bare orderby toggle, which
			// always sorted descending and could look like nothing happened).
			if ("time" === logsState.orderby) {
				logsState.orderby = "name";
				logsState.order = "ASC";
			} else if ("name" === logsState.orderby && "ASC" === logsState.order) {
				logsState.order = "DESC";
			} else {
				logsState.orderby = "time";
				logsState.order = "DESC";
			}
			updateSortButtonLabel(sortBtn);
			logsState.page = 1;
			loadLogs();
		});

		var clearBtn = document.getElementById("bg-log-clear-filter");
		if (clearBtn) {
			clearBtn.addEventListener("click", function () {
				window.location.href = cfg.logsTabUrl;
			});
		}

		document
			.getElementById("bg-export-wipe")
			.addEventListener("click", onExportWipeClick);

		loadLogs();
		loadBackups();
	}

	function updateSortButtonLabel(btn) {
		if ("name" === logsState.orderby) {
			btn.textContent = "Sort: Name (" + ("ASC" === logsState.order ? "A→Z" : "Z→A") + ") — click for Time";
		} else {
			btn.textContent = "Sort: Time (Newest First) — click for Name";
		}
	}

	function loadLogs() {
		var qs = new URLSearchParams({
			search: logsState.search,
			user_id: logsState.user_id,
			orderby: logsState.orderby,
			order: logsState.order,
			page: logsState.page,
		}).toString();

		apiFetch("/admin/logs?" + qs)
			.then(renderLogRows)
			.catch(showError);
	}

	function renderLogRows(result) {
		var tbody = document.getElementById("bg-log-rows");
		tbody.innerHTML = "";

		result.rows.forEach(function (row) {
			var tr = document.createElement("tr");
			tr.appendChild(td(row.created_at));

			var nameTd = document.createElement("td");
			var link = document.createElement("a");
			link.href = cfg.logsTabUrl + "&user_id=" + row.user_id;
			link.textContent = row.display_name || "User #" + row.user_id;
			nameTd.appendChild(link);
			tr.appendChild(nameTd);

			var statusTd = document.createElement("td");
			statusTd.appendChild(buildStatusBadge(row.scan_status, row.confidence_score));
			tr.appendChild(statusTd);

			tr.appendChild(td(row.page_title || row.page_url));

			tbody.appendChild(tr);
		});

		renderPagination(result.total);
	}

	function renderPagination(total) {
		var perPage = 50;
		var totalPages = Math.max(1, Math.ceil(total / perPage));
		var el = document.getElementById("bg-log-pagination");
		el.innerHTML = "";
		el.appendChild(
			document.createTextNode(
				"Page " +
				logsState.page +
				" of " +
				totalPages +
				" (" +
				total +
				" rows) ",
			),
		);

		var prev = document.createElement("button");
		prev.type = "button";
		prev.className = "button";
		prev.textContent = "Prev";
		prev.disabled = logsState.page <= 1;
		prev.addEventListener("click", function () {
			logsState.page--;
			loadLogs();
		});

		var next = document.createElement("button");
		next.type = "button";
		next.className = "button";
		next.textContent = "Next";
		next.disabled = logsState.page >= totalPages;
		next.addEventListener("click", function () {
			logsState.page++;
			loadLogs();
		});

		el.appendChild(prev);
		el.appendChild(document.createTextNode(" "));
		el.appendChild(next);
	}

	function onExportWipeClick() {
		var confirmMsg = logsState.user_id
			? "This will export this student's log rows to a CSV backup, then permanently erase them. Continue?"
			: cfg.i18n.confirmExportWipe;

		if (!window.confirm(confirmMsg)) {
			return;
		}

		var status = document.getElementById("bg-export-wipe-status");
		status.textContent = cfg.i18n.exporting;

		apiFetch("/admin/export-wipe", {
			method: "POST",
			body: { user_id: logsState.user_id || 0 },
		})
			.then(function (res) {
				var key = res.progress_key;
				var interval = window.setInterval(function () {
					apiFetch("/admin/export-progress?key=" + encodeURIComponent(key)).then(function (progress) {
						if ("done" === progress.status) {
							window.clearInterval(interval);
							status.textContent = cfg.i18n.done;
							loadLogs();
							loadBackups();
						} else if ("error" === progress.status) {
							window.clearInterval(interval);
							status.textContent = "Error: " + (progress.message || "Export failed.");
						}
					});
				}, 1500);
			})
			.catch(showError);
	}

	function loadBackups() {
		apiFetch("/admin/backups").then(renderBackupRows).catch(showError);
	}

	function renderBackupRows(files) {
		var tbody = document.getElementById("bg-backup-rows");
		tbody.innerHTML = "";

		files.forEach(function (f) {
			var tr = document.createElement("tr");
			tr.appendChild(td(f.name));
			tr.appendChild(td(humanSize(f.size)));
			tr.appendChild(td(new Date(f.modified * 1000).toLocaleString()));

			var actionsTd = document.createElement("td");
			var dl = document.createElement("a");
			dl.className = "button";
			dl.textContent = "Download";
			dl.href =
				cfg.downloadBaseUrl +
				"?action=bg_download_backup&file=" +
				encodeURIComponent(f.name) +
				"&_wpnonce=" +
				cfg.downloadNonce;

			var del = document.createElement("button");
			del.type = "button";
			del.className = "button";
			del.textContent = "Delete";
			del.addEventListener("click", function () {
				if (!window.confirm(cfg.i18n.confirmDeleteFile)) {
					return;
				}
				apiFetch("/admin/backups/delete", {
					method: "POST",
					body: { file: f.name },
				})
					.then(loadBackups)
					.catch(showError);
			});

			actionsTd.appendChild(dl);
			actionsTd.appendChild(document.createTextNode(" "));
			actionsTd.appendChild(del);
			tr.appendChild(actionsTd);

			tbody.appendChild(tr);
		});
	}

	if ("a" === cfg.tab) {
		renderUsersTab();
	} else if ("c" === cfg.tab) {
		renderLogsTab();
	}
})();
