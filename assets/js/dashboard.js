/**
 * License Server Dashboard JavaScript
 *
 * Handles real-time updates, charts, and user interactions
 */
(function ($) {
  "use strict";

  /**
   * Dashboard Controller
   */
  class LicenseServerDashboard {
    constructor() {
      this.charts = {};
      this.refreshInterval = null;
      this.isRefreshing = false;

      this.init();
    }

    /**
     * Initialize dashboard
     */
    init() {
      this.bindEvents();
      this.initializeCharts();
      this.startAutoRefresh();
      this.loadInitialData();
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
      // Refresh button
      $("#lsr-refresh-dashboard").on("click", () => {
        this.refreshDashboard();
      });

      // Chart period selector
      $("#lsr-chart-period").on("change", (e) => {
        this.updateChartPeriod($(e.target).val());
      });

      // Export data button
      $("#lsr-export-data").on("click", () => {
        this.exportData();
      });

      // Real-time toggle (if exists)
      $("#lsr-realtime-toggle").on("change", (e) => {
        this.toggleRealtime($(e.target).is(":checked"));
      });

      // Window focus/blur for performance
      $(window)
        .on("focus", () => {
          this.startAutoRefresh();
        })
        .on("blur", () => {
          this.stopAutoRefresh();
        });

      // Responsive chart resize
      $(window).on(
        "resize",
        this.debounce(() => {
          this.resizeCharts();
        }, 250)
      );
    }

    /**
     * Initialize Chart.js charts
     */
    initializeCharts() {
      this.initializeLicenseActivityChart();
      this.initializeLicenseStatusChart();
    }

    /**
     * Initialize license activity chart
     */
    initializeLicenseActivityChart() {
      const ctx = document.getElementById("lsr-license-activity-chart");
      if (!ctx) return;

      this.charts.licenseActivity = new Chart(ctx, {
        type: "line",
        data: {
          labels: [],
          datasets: [
            {
              label: lsrDashboard.strings.licenses || "Licenses",
              data: [],
              borderColor: "#0073aa",
              backgroundColor: "rgba(0, 115, 170, 0.1)",
              borderWidth: 2,
              fill: true,
              tension: 0.4,
              pointBackgroundColor: "#0073aa",
              pointBorderColor: "#ffffff",
              pointBorderWidth: 2,
              pointRadius: 4,
              pointHoverRadius: 6,
            },
            {
              label: lsrDashboard.strings.activations || "Activations",
              data: [],
              borderColor: "#00a32a",
              backgroundColor: "rgba(0, 163, 42, 0.1)",
              borderWidth: 2,
              fill: true,
              tension: 0.4,
              pointBackgroundColor: "#00a32a",
              pointBorderColor: "#ffffff",
              pointBorderWidth: 2,
              pointRadius: 4,
              pointHoverRadius: 6,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            intersect: false,
            mode: "index",
          },
          plugins: {
            legend: {
              display: true,
              position: "top",
              align: "end",
            },
            tooltip: {
              backgroundColor: "rgba(0, 0, 0, 0.8)",
              titleColor: "#ffffff",
              bodyColor: "#ffffff",
              borderColor: "#dcdcde",
              borderWidth: 1,
              cornerRadius: 8,
              displayColors: true,
            },
          },
          scales: {
            x: {
              display: true,
              grid: {
                display: false,
              },
              ticks: {
                color: "#646970",
              },
            },
            y: {
              display: true,
              beginAtZero: true,
              grid: {
                color: "rgba(220, 220, 222, 0.3)",
              },
              ticks: {
                color: "#646970",
                precision: 0,
              },
            },
          },
          animation: {
            duration: 750,
            easing: "easeInOutQuart",
          },
        },
      });
    }

    /**
     * Initialize license status chart
     */
    initializeLicenseStatusChart() {
      const ctx = document.getElementById("lsr-license-status-chart");
      if (!ctx) return;

      this.charts.licenseStatus = new Chart(ctx, {
        type: "doughnut",
        data: {
          labels: [],
          datasets: [
            {
              data: [],
              backgroundColor: [
                "#0073aa", // active
                "#00a32a", // pending
                "#dba617", // expired
                "#d63638", // suspended
                "#72777c", // other
              ],
              borderColor: "#ffffff",
              borderWidth: 2,
              hoverBorderWidth: 3,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              position: "bottom",
              labels: {
                usePointStyle: true,
                padding: 15,
                color: "#646970",
              },
            },
            tooltip: {
              backgroundColor: "rgba(0, 0, 0, 0.8)",
              titleColor: "#ffffff",
              bodyColor: "#ffffff",
              borderColor: "#dcdcde",
              borderWidth: 1,
              cornerRadius: 8,
              callbacks: {
                label: function (context) {
                  const label = context.label || "";
                  const value = context.parsed || 0;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const percentage = ((value / total) * 100).toFixed(1);
                  return `${label}: ${value} (${percentage}%)`;
                },
              },
            },
          },
          animation: {
            animateScale: true,
            duration: 1000,
          },
        },
      });
    }

    /**
     * Load initial dashboard data
     */
    loadInitialData() {
      this.showLoading();

      Promise.all([this.fetchDashboardStats(), this.fetchRecentActivity(), this.fetchChartData(30)])
        .then(() => {
          this.hideLoading();
          this.updateLastUpdatedTime();
        })
        .catch((error) => {
          console.error("Failed to load initial data:", error);
          this.showError("Failed to load dashboard data");
        });
    }

    /**
     * Refresh dashboard data
     */
    refreshDashboard() {
      if (this.isRefreshing) return;

      this.isRefreshing = true;
      const $refreshBtn = $("#lsr-refresh-dashboard");
      const originalText = $refreshBtn.find("span:not(.dashicons)").text();

      $refreshBtn.prop("disabled", true).find(".dashicons").addClass("lsr-spinning");

      Promise.all([this.fetchDashboardStats(), this.fetchRecentActivity(), this.fetchRealtimeStats()])
        .then(() => {
          this.updateLastUpdatedTime();
        })
        .catch((error) => {
          console.error("Failed to refresh dashboard:", error);
          this.showNotification("Failed to refresh data", "error");
        })
        .finally(() => {
          this.isRefreshing = false;
          $refreshBtn.prop("disabled", false).find(".dashicons").removeClass("lsr-spinning");
        });
    }

    /**
     * Fetch dashboard statistics
     */
    fetchDashboardStats() {
      return new Promise((resolve, reject) => {
        $.ajax({
          url: lsrDashboard.ajaxUrl,
          type: "POST",
          data: {
            action: "lsr_dashboard_data",
            type: "stats",
            nonce: lsrDashboard.nonce,
          },
          success: (response) => {
            if (response.success) {
              this.updateMetricCards(response.data);
              resolve(response.data);
            } else {
              reject(new Error(response.data.message || "Failed to fetch stats"));
            }
          },
          error: (xhr, status, error) => {
            reject(new Error(`AJAX error: ${error}`));
          },
        });
      });
    }

    /**
     * Fetch recent activity
     */
    fetchRecentActivity() {
      return new Promise((resolve, reject) => {
        $.ajax({
          url: lsrDashboard.ajaxUrl,
          type: "POST",
          data: {
            action: "lsr_dashboard_data",
            type: "activity",
            nonce: lsrDashboard.nonce,
          },
          success: (response) => {
            if (response.success) {
              this.updateRecentActivity(response.data);
              resolve(response.data);
            } else {
              reject(new Error(response.data.message || "Failed to fetch activity"));
            }
          },
          error: (xhr, status, error) => {
            reject(new Error(`AJAX error: ${error}`));
          },
        });
      });
    }

    /**
     * Fetch chart data
     */
    fetchChartData(period = 30) {
      return new Promise((resolve, reject) => {
        $.ajax({
          url: lsrDashboard.ajaxUrl,
          type: "POST",
          data: {
            action: "lsr_dashboard_data",
            type: "charts",
            period: period,
            nonce: lsrDashboard.nonce,
          },
          success: (response) => {
            if (response.success) {
              this.updateCharts(response.data);
              resolve(response.data);
            } else {
              reject(new Error(response.data.message || "Failed to fetch chart data"));
            }
          },
          error: (xhr, status, error) => {
            reject(new Error(`AJAX error: ${error}`));
          },
        });
      });
    }

    /**
     * Fetch real-time statistics
     */
    fetchRealtimeStats() {
      return new Promise((resolve, reject) => {
        $.ajax({
          url: lsrDashboard.ajaxUrl,
          type: "POST",
          data: {
            action: "lsr_realtime_stats",
            nonce: lsrDashboard.nonce,
          },
          success: (response) => {
            if (response.success) {
              this.updateRealtimeMetrics(response.data);
              resolve(response.data);
            } else {
              reject(new Error(response.data.message || "Failed to fetch realtime stats"));
            }
          },
          error: (xhr, status, error) => {
            reject(new Error(`AJAX error: ${error}`));
          },
        });
      });
    }

    /**
     * Update metric cards with new data
     */
    updateMetricCards(data) {
      $("#lsr-total-licenses").text(this.formatNumber(data.total_licenses));
      $("#lsr-active-licenses").text(this.formatNumber(data.active_licenses));
      $("#lsr-downloads-today").text(this.formatNumber(data.downloads_today));
      $("#lsr-security-events").text(this.formatNumber(data.security_events));

      // Update growth indicators
      this.updateGrowthIndicator("#lsr-total-licenses", data.licenses_growth);
      this.updateGrowthIndicator("#lsr-active-licenses", data.active_growth);
      this.updateGrowthIndicator("#lsr-downloads-today", data.downloads_change);
      this.updateGrowthIndicator("#lsr-security-events", data.security_change);

      // Animate number changes
      this.animateNumbers();
    }

    /**
     * Update growth indicator
     */
    updateGrowthIndicator(selector, growth) {
      const $card = $(selector).closest(".lsr-metric-card");
      const $change = $card.find(".lsr-metric-change");

      $change
        .text(`${growth > 0 ? "+" : ""}${growth}%`)
        .removeClass("lsr-metric-up lsr-metric-down")
        .addClass(growth >= 0 ? "lsr-metric-up" : "lsr-metric-down");
    }

    /**
     * Update recent activity list
     */
    updateRecentActivity(activities) {
      const $container = $("#lsr-recent-activity");

      if (!activities || activities.length === 0) {
        $container.html('<p class="lsr-no-activity">' + (lsrDashboard.strings.no_activity || "No recent activity") + "</p>");
        return;
      }

      let html = '<div class="lsr-activity-list">';
      activities.forEach((activity) => {
        html += `
                    <div class="lsr-activity-item lsr-fade-in">
                        <span class="lsr-activity-icon dashicons ${activity.icon}"></span>
                        <div class="lsr-activity-content">
                            <div class="lsr-activity-message">${activity.message}</div>
                            <div class="lsr-activity-time">${activity.time_ago} ago</div>
                        </div>
                    </div>
                `;
      });
      html += "</div>";

      $container.html(html);
    }

    /**
     * Update charts with new data
     */
    updateCharts(data) {
      if (data.license_activity && this.charts.licenseActivity) {
        this.updateLicenseActivityChart(data.license_activity);
      }

      if (data.status_distribution && this.charts.licenseStatus) {
        this.updateLicenseStatusChart(data.status_distribution);
      }
    }

    /**
     * Update license activity chart
     */
    updateLicenseActivityChart(data) {
      const chart = this.charts.licenseActivity;

      chart.data.labels = data.map((item) => this.formatDate(item.date));
      chart.data.datasets[0].data = data.map((item) => item.licenses || 0);
      chart.data.datasets[1].data = data.map((item) => item.activations || 0);

      chart.update("active");
    }

    /**
     * Update license status chart
     */
    updateLicenseStatusChart(data) {
      const chart = this.charts.licenseStatus;

      chart.data.labels = data.map((item) => this.capitalizeFirst(item.status));
      chart.data.datasets[0].data = data.map((item) => parseInt(item.count));

      chart.update("active");
    }

    /**
     * Update real-time metrics
     */
    updateRealtimeMetrics(data) {
      // Update specific real-time indicators if they exist
      if (data.api_requests_per_minute !== undefined) {
        $(".lsr-api-requests").text(this.formatNumber(data.api_requests_per_minute, 1));
      }

      if (data.cache_hit_rate !== undefined) {
        $(".lsr-cache-hit-rate").text(data.cache_hit_rate + "%");
      }

      if (data.system_load !== undefined) {
        $(".lsr-system-load").text(this.formatNumber(data.system_load.cpu, 2));
      }
    }

    /**
     * Update chart period
     */
    updateChartPeriod(period) {
      this.fetchChartData(parseInt(period));
    }

    /**
     * Start auto-refresh
     */
    startAutoRefresh() {
      this.stopAutoRefresh();

      if (lsrDashboard.refreshInterval > 0) {
        this.refreshInterval = setInterval(() => {
          this.fetchRealtimeStats();
        }, lsrDashboard.refreshInterval);
      }
    }

    /**
     * Stop auto-refresh
     */
    stopAutoRefresh() {
      if (this.refreshInterval) {
        clearInterval(this.refreshInterval);
        this.refreshInterval = null;
      }
    }

    /**
     * Toggle real-time updates
     */
    toggleRealtime(enabled) {
      if (enabled) {
        this.startAutoRefresh();
      } else {
        this.stopAutoRefresh();
      }
    }

    /**
     * Export dashboard data
     */
    exportData() {
      const exportData = {
        timestamp: new Date().toISOString(),
        metrics: this.getCurrentMetrics(),
        charts: this.getCurrentChartData(),
      };

      this.downloadJSON(exportData, `license-server-dashboard-${this.formatDateForFilename(new Date())}.json`);
    }

    /**
     * Resize charts on window resize
     */
    resizeCharts() {
      Object.values(this.charts).forEach((chart) => {
        if (chart && chart.resize) {
          chart.resize();
        }
      });
    }

    /**
     * Show loading state
     */
    showLoading() {
      $(".lsr-dashboard-grid").addClass("lsr-loading-state");
    }

    /**
     * Hide loading state
     */
    hideLoading() {
      $(".lsr-dashboard-grid").removeClass("lsr-loading-state");
    }

    /**
     * Show error message
     */
    showError(message) {
      this.showNotification(message, "error");
    }

    /**
     * Show notification
     */
    showNotification(message, type = "info") {
      // Create notification element
      const $notification = $(`
                <div class="lsr-notification lsr-notification-${type}">
                    <span class="dashicons dashicons-${type === "error" ? "warning" : "info"}"></span>
                    <span class="lsr-notification-message">${message}</span>
                    <button class="lsr-notification-close">&times;</button>
                </div>
            `);

      // Add to page
      $("body").append($notification);

      // Show notification
      setTimeout(() => {
        $notification.addClass("lsr-notification-show");
      }, 100);

      // Auto-hide after 5 seconds
      setTimeout(() => {
        $notification.removeClass("lsr-notification-show");
        setTimeout(() => $notification.remove(), 300);
      }, 5000);

      // Manual close
      $notification.find(".lsr-notification-close").on("click", () => {
        $notification.removeClass("lsr-notification-show");
        setTimeout(() => $notification.remove(), 300);
      });
    }

    /**
     * Update last updated time
     */
    updateLastUpdatedTime() {
      $("#lsr-last-updated-time").text(new Date().toLocaleTimeString());
    }

    /**
     * Animate numbers
     */
    animateNumbers() {
      $(".lsr-metric-number").each(function () {
        const $this = $(this);
        const value = parseInt($this.text().replace(/,/g, ""));

        if (isNaN(value)) return;

        $({ countNum: 0 }).animate(
          { countNum: value },
          {
            duration: 1000,
            easing: "swing",
            step: function () {
              $this.text(Math.floor(this.countNum).toLocaleString());
            },
            complete: function () {
              $this.text(value.toLocaleString());
            },
          }
        );
      });
    }

    // Utility methods

    formatNumber(num, decimals = 0) {
      if (num === null || num === undefined) return "0";
      return parseFloat(num).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
    }

    formatDate(dateString) {
      const date = new Date(dateString);
      return date.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
      });
    }

    formatDateForFilename(date) {
      return date.toISOString().split("T")[0];
    }

    capitalizeFirst(str) {
      return str.charAt(0).toUpperCase() + str.slice(1);
    }

    debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }

    downloadJSON(data, filename) {
      const blob = new Blob([JSON.stringify(data, null, 2)], {
        type: "application/json",
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");

      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    getCurrentMetrics() {
      return {
        total_licenses: $("#lsr-total-licenses").text(),
        active_licenses: $("#lsr-active-licenses").text(),
        downloads_today: $("#lsr-downloads-today").text(),
        security_events: $("#lsr-security-events").text(),
      };
    }

    getCurrentChartData() {
      const data = {};

      Object.keys(this.charts).forEach((key) => {
        const chart = this.charts[key];
        if (chart && chart.data) {
          data[key] = {
            labels: chart.data.labels,
            datasets: chart.data.datasets.map((dataset) => ({
              label: dataset.label,
              data: dataset.data,
            })),
          };
        }
      });

      return data;
    }
  }

  // Initialize dashboard when DOM is ready
  $(document).ready(function () {
    if ($(".lsr-dashboard").length > 0) {
      window.lsrDashboardInstance = new LicenseServerDashboard();
    }
  });

  // Add spinning animation CSS
  $("<style>")
    .prop("type", "text/css")
    .html(
      `
            .lsr-spinning {
                animation: lsr-spin 1s linear infinite;
            }
            
            @keyframes lsr-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .lsr-notification {
                position: fixed;
                top: 32px;
                right: 20px;
                background: white;
                border-left: 4px solid #0073aa;
                box-shadow: 0 4px 16px rgba(0,0,0,0.1);
                padding: 12px 16px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 8px;
                z-index: 999999;
                transform: translateX(100%);
                transition: transform 0.3s ease;
                min-width: 300px;
            }
            
            .lsr-notification-show {
                transform: translateX(0);
            }
            
            .lsr-notification-error {
                border-left-color: #d63638;
            }
            
            .lsr-notification-close {
                background: none;
                border: none;
                font-size: 18px;
                cursor: pointer;
                padding: 0;
                margin-left: auto;
            }
            
            .lsr-loading-state {
                opacity: 0.7;
                pointer-events: none;
            }
        `
    )
    .appendTo("head");
})(jQuery);
