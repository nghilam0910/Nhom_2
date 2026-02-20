// moonlit.js
document.addEventListener('DOMContentLoaded', function () {
  console.log('Moonlit JS loaded ✨');
  // Ví dụ: thêm shadow cho header khi cuộn xuống
  const header = document.querySelector('.site-header');
  if (header) {
    window.addEventListener('scroll', function () {
      if (window.scrollY > 10) {
        header.classList.add('is-scrolled');
      } else {
        header.classList.remove('is-scrolled');
      }
    });
  }

  // SCRIPT ĐỊA CHÍNH VIỆT NAM TRÊN TRANG ACCOUNT-PROFILE
  const isProfilePage = document.querySelector('[data-page-id="profile-page"]');
  if (isProfilePage) {
    const citySelect = document.getElementById('city');
    const districtSelect = document.getElementById('district');
    const wardSelect = document.getElementById('ward');
    
    // Lấy giá trị đã lưu trong DB (nếu có)
    const savedCity = document.getElementById('saved_city').value;
    const savedDistrict = document.getElementById('saved_district').value;
    const savedWard = document.getElementById('saved_ward').value;

    // API Endpoint (Sử dụng API miễn phí phổ biến cho hành chính VN)
    const API_URL = "https://provinces.open-api.vn/api/?depth=3";

    // Hàm fetch dữ liệu
    async function loadVietnamData() {
        try {
            const response = await axios.get(API_URL);
            const data = response.data;
            
            // 1. Render Cities
            renderOptions(citySelect, data, 'name');
            
            // Nếu có savedCity, chọn nó và kích hoạt render District
            if (savedCity) {
                setSelectValue(citySelect, savedCity);
                
                const selectedCityData = data.find(item => item.name === savedCity);
                if (selectedCityData) {
                    // 2. Render Districts dựa trên City đã lưu
                    renderOptions(districtSelect, selectedCityData.districts, 'name');
                    districtSelect.disabled = false;

                    if (savedDistrict) {
                        setSelectValue(districtSelect, savedDistrict);

                        const selectedDistrictData = selectedCityData.districts.find(item => item.name === savedDistrict);
                        if (selectedDistrictData) {
                            // 3. Render Wards dựa trên District đã lưu
                            renderOptions(wardSelect, selectedDistrictData.wards, 'name');
                            wardSelect.disabled = false;
                            
                            if (savedWard) {
                                setSelectValue(wardSelect, savedWard);
                            } 
                        }
                    }
                }
            }
        } catch (error) {
            console.error("Lỗi khi tải dữ liệu hành chính:", error);
      }
    }

    // Khởi chạy
    loadVietnamData();

    // Sự kiện khi thay đổi City
    citySelect.addEventListener('change', async function() {
        districtSelect.innerHTML = '<option value="" selected>Chọn Quận/Huyện</option>';
        wardSelect.innerHTML = '<option value="" selected>Chọn Phường/Xã</option>';
        districtSelect.disabled = true;
        wardSelect.disabled = true;

        const selectedCityName = this.value;
        if (!selectedCityName) return;

        // Tìm data của City đang chọn (phải fetch lại hoặc lưu cache, ở đây ta fetch từ API global store nếu muốn tối ưu, 
        // nhưng để đơn giản ta gọi lại axios hoặc lưu biến global. Cách dưới dùng biến global lưu tạm)
        // Cách đơn giản nhất: Lấy từ data đã fetch (nhưng biến data nằm trong scope hàm load). 
        // => Gọi lại API hoặc lưu data ra ngoài.
        // Để code gọn, ta sẽ fetch lại từ cache browser (axios tự cache) hoặc dùng biến global.
        
        // *Giải pháp tốt nhất trong đoạn code nhỏ:* Lưu data vào window object khi load lần đầu
        if (window.vnData) {
            const cityData = window.vnData.find(c => c.name === selectedCityName);
            if (cityData) {
                renderOptions(districtSelect, cityData.districts, 'name');
                districtSelect.disabled = false;
            }
        } else {
             // Fallback nếu chưa lưu
            const response = await axios.get(API_URL);
            const data = response.data;
            const cityData = data.find(c => c.name === selectedCityName);
             if (cityData) {
                renderOptions(districtSelect, cityData.districts, 'name');
                districtSelect.disabled = false;
            }
        }
    });

    // Sự kiện khi thay đổi District
    districtSelect.addEventListener('change', async function() {
        wardSelect.innerHTML = '<option value="" selected>Chọn Phường/Xã</option>';
        wardSelect.disabled = true;

        const selectedCityName = citySelect.value;
        const selectedDistrictName = this.value;
        if (!selectedDistrictName) return;

        // Logic lấy data tương tự trên
        let data = window.vnData;
        if (!data) {
             const response = await axios.get(API_URL);
             data = response.data;
        }

        const cityData = data.find(c => c.name === selectedCityName);
        if (cityData) {
            const districtData = cityData.districts.find(d => d.name === selectedDistrictName);
            if (districtData) {
                renderOptions(wardSelect, districtData.wards, 'name');
                wardSelect.disabled = false;
            }
        }
    });

    // Helper: Render options
    function renderOptions(selectElement, dataArray, keyName) {
        dataArray.forEach(item => {
            const option = document.createElement('option');
            option.value = item[keyName]; // Lưu tên (Vd: "Hà Nội") vào value để lưu xuống DB
            option.text = item[keyName];
            selectElement.appendChild(option);
        });
    }

    // Helper: Set value an toàn
    function setSelectValue(selectElement, value) {
        for (let i = 0; i < selectElement.options.length; i++) {
            if (selectElement.options[i].value === value) {
                selectElement.selectedIndex = i;
                break;
            }
        }
    }
    
    // Lưu data ra global để dùng lại trong sự kiện change
    axios.get(API_URL).then(res => { window.vnData = res.data; });

  }

  // SCRIPT HỦY ĐƠN HÀNG TRÊN TRANG ACCOUNT-TRACKING
  const isTrackingPage = document.querySelector('[data-page-id="tracking-page"]');
  if (isTrackingPage) {
    // PHẢI dùng window. để HTML onclick="openCancelModal" nhận diện được
        window.openCancelModal = function(orderId) {
            const modal = document.getElementById('cancelOrderModal');
            const input = document.getElementById('modal_order_id');
            if (modal && input) {
                input.value = orderId;
                modal.style.display = 'flex';
            }
        }

        window.closeCancelModal = function() {
            const modal = document.getElementById('cancelOrderModal');
            if (modal) modal.style.display = 'none';
        }

        // Đóng modal khi click ra ngoài
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('cancelOrderModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        });
    }

  // SCRIPT QUẢN LÝ ĐƠN HÀNG (TRẢ HÀNG & THEO DÕI TRẢ HÀNG) TRÊN TRANG ACCOUNT-ORDERS
  const isOrdersPage = document.querySelector('[data-page-id="orders-page"]');
  if (isOrdersPage) {
    // 1. Hàm khởi tạo form trả hàng (Gắn vào window để onclick trong PHP gọi được)
    window.setReturnOrderData = function(orderId, orderCard) {
        document.getElementById('return_order_id').value = orderId;
        const container = document.getElementById('return_items_container');
        container.innerHTML = '';

        // Tìm tất cả item trong card đơn hàng vừa click
        const items = orderCard.querySelectorAll('.account-order-item');
        let html = '<div class="account-return-items-form">';
        
        items.forEach(el => {
            // Lấy ID và Số lượng từ data attribute
            const itemId = el.dataset.orderItemId; 
            const maxQty = el.dataset.maxQty;
            const name = el.querySelector('.account-order-item-name').innerText;
            
            let skuText = '';
            const skuEl = el.querySelector('.account-order-item-sku');
            if (skuEl) skuText = skuEl.innerText;

            html += `
            <div class="account-return-item-section account-orders-return-item">
                <div class="account-return-item-header">
                    <div class="form-check">
                        <input class="form-check-input return-item-check" type="checkbox" 
                               name="return_items[]" value="${itemId}" 
                               onchange="toggleItemReturn(this, '${itemId}')">
                        <label class="form-check-label account-orders-return-product-name">${name}</label>
                        <div class="account-orders-return-sku">${skuText}</div>
                    </div>
                </div>

                <div id="return_detail_${itemId}" class="account-return-item-details account-orders-return-details" style="display:none;">
                    <div class="form-group mb-2">
                        <label class="form-label">Số lượng trả (Max: ${maxQty}) <span class="text-danger">*</span></label>
                        <input type="number" name="return_quantities[${itemId}]" class="form-control" min="1" max="${maxQty}" value="1">
                    </div>

                    <div class="form-group mb-2">
                        <label class="form-label">Lý do trả hàng <span class="text-danger">*</span></label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="return_reasons[${itemId}]" value="Sản phẩm bị lỗi/hư hỏng">
                                <label class="form-check-label">Sản phẩm lỗi</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="return_reasons[${itemId}]" value="Giao sai hàng">
                                <label class="form-check-label">Giao sai hàng</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="return_reasons[${itemId}]" value="Hàng hư hại do vận chuyển">
                                <label class="form-check-label">Hàng hư hại do vận chuyển</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Hình ảnh minh chứng <span class="text-danger">*</span></label>
                        <input type="file" name="return_images[${itemId}][]" class="form-control" multiple accept="image/*">
                    </div>
                </div>
            </div>`;
        });

        html += '</div>';
        container.innerHTML = html;
    };

    // 2. Hàm ẩn hiện chi tiết khi tick checkbox
    window.toggleItemReturn = function(checkbox, itemId) {
        const detailDiv = document.getElementById('return_detail_' + itemId);
        if(!detailDiv) return;
        const inputs = detailDiv.querySelectorAll('input');
        
        if (checkbox.checked) {
            detailDiv.style.display = 'block';
            inputs.forEach(input => input.required = true);
        } else {
            detailDiv.style.display = 'none';
            inputs.forEach(input => input.required = false);
        }
    };

    // 3. Hàm Validate trước khi Submit
    window.validateReturnForm = function() {
        const checked = document.querySelectorAll('.return-item-check:checked');
        if (checked.length === 0) {
            alert('Vui lòng chọn ít nhất 1 sản phẩm để trả.');
            return false;
        }
        return true;
    };

    // 4. Hàm Xem tiến độ trả hàng (Fetch API)
    window.setViewReturnData = function(returnId) {
        fetch('get_return_details.php?return_id=' + encodeURIComponent(returnId))
            .then(res => res.json())
            .then(data => {
                if(!data.success) { alert(data.message); return; }
                
                const ret = data.return;
                const timelineDiv = document.getElementById('return_timeline_container');
                const successDiv = document.getElementById('return_success_message');
                const itemsDiv = document.getElementById('return_items_info_container');
                const cancelBtn = document.getElementById('cancel_return_btn');
                
                // Gán ID vào nút hủy yêu cầu (nếu cần)
                const cancelInput = document.getElementById('cancel_return_id_input');
                if(cancelInput) cancelInput.value = ret.returnId;

                const steps = ['Chờ xác nhận', 'Đã xác nhận', 'Đang tới lấy', 'Đang trả về', 'Kiểm hàng', 'Chấp thuận'];

                if (ret.status === 'Chấp thuận') {
                    timelineDiv.style.display = 'none';
                    successDiv.style.display = 'block';
                    document.getElementById('return_refund_text').innerHTML = 
                        `Đã hoàn lại <strong>${new Intl.NumberFormat('vi-VN').format(ret.totalRefund)} đ</strong> vào ví của bạn.`;
                    cancelBtn.style.display = 'none';
                } else {
                    successDiv.style.display = 'none';
                    timelineDiv.style.display = 'flex'; 
                    
                    let html = '';
                    const currentIdx = steps.indexOf(ret.status);
                    
                    steps.forEach((step, idx) => {
                        const isActiveStep = idx <= currentIdx;
                        const isActiveLine = idx < currentIdx;

                        html += `
                        <div class="account-tracking-step ${isActiveStep ? 'account-tracking-step-active' : ''}">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">${step}</div>
                        </div>`;

                        if(idx < steps.length - 1) {
                            html += `<div class="account-tracking-line ${isActiveLine ? 'account-tracking-line-active' : ''}"></div>`;
                        }
                    });
                    timelineDiv.innerHTML = html;
                    cancelBtn.style.display = (ret.status === 'Chờ xác nhận') ? 'inline-block' : 'none';
                }

                // Render danh sách sản phẩm trả lại
                let itemsHtml = '<h5>Sản phẩm trả lại</h5>';
                data.items.forEach(item => {
                    itemsHtml += `
                    <div class="p-2 mb-2 bg-light border rounded">
                        <strong>${item.productName}</strong><br>
                        <small>SL: ${item.quantity} | Lý do: ${item.reason}</small>
                    </div>`;
                });
                itemsDiv.innerHTML = itemsHtml;
            })
            .catch(err => console.error("Lỗi fetch:", err));
    };
  }

    // SCRIPT ĐỊA CHÍNH VIỆT NAM TRÊN TRANG ADMIN-SETTING
    const isSettingPage = document.querySelector('[data-page-id="admin-setting"]');
    if (isSettingPage) {
        const citySelect = document.getElementById('company_city');
        const districtSelect = document.getElementById('company_district');
        const wardSelect = document.getElementById('company_ward');
        const savedCity = document.getElementById('saved_city')?.value;
        const savedDistrict = document.getElementById('saved_district')?.value;
        const savedWard = document.getElementById('saved_ward')?.value;

        const API_URL = "https://provinces.open-api.vn/api/?depth=3";

        async function loadSettingAddress() {
            try {
                let data = window.vnData;
                if (!data) {
                    const response = await axios.get(API_URL);
                    data = response.data;
                    window.vnData = data;
                }

                renderOptions(citySelect, data, 'name');

                if (savedCity) {
                    setSelectValue(citySelect, savedCity);
                    const cityData = data.find(item => item.name === savedCity);
                    if (cityData) {
                        renderOptions(districtSelect, cityData.districts, 'name');
                        districtSelect.disabled = false;
                        if (savedDistrict) {
                            setSelectValue(districtSelect, savedDistrict);
                            const districtData = cityData.districts.find(item => item.name === savedDistrict);
                            if (districtData) {
                                renderOptions(wardSelect, districtData.wards, 'name');
                                wardSelect.disabled = false;
                                if (savedWard) setSelectValue(wardSelect, savedWard);
                            }
                        }
                    }
                }
            } catch (e) { console.error("Lỗi tải địa chỉ setting:", e); }
        }

        loadSettingAddress();

        citySelect?.addEventListener('change', function() {
            districtSelect.innerHTML = '<option value="">Chọn Quận/Huyện</option>';
            wardSelect.innerHTML = '<option value="">Chọn Phường/Xã</option>';
            districtSelect.disabled = true; wardSelect.disabled = true;
            const cityData = window.vnData?.find(c => c.name === this.value);
            if (cityData) {
                renderOptions(districtSelect, cityData.districts, 'name');
                districtSelect.disabled = false;
            }
        });

        districtSelect?.addEventListener('change', function() {
            wardSelect.innerHTML = '<option value="">Chọn Phường/Xã</option>';
            wardSelect.disabled = true;
            const cityData = window.vnData?.find(c => c.name === citySelect.value);
            const districtData = cityData?.districts.find(d => d.name === this.value);
            if (districtData) {
                renderOptions(wardSelect, districtData.wards, 'name');
                wardSelect.disabled = false;
            }
        });
        function renderOptions(selectElement, dataArray, keyName) {
            if (!selectElement || !dataArray) return;
            dataArray.forEach(item => {
                const option = document.createElement('option');
                option.value = item[keyName];
                option.text = item[keyName];
                selectElement.appendChild(option);
            });
        }

        function setSelectValue(selectElement, value) {
            if (!selectElement || !value) return;
            for (let i = 0; i < selectElement.options.length; i++) {
                if (selectElement.options[i].value === value) {
                    selectElement.selectedIndex = i;
                    break;
                }
            }
        }
    }

});




