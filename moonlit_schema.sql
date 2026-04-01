CREATE DATABASE moonlit
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

CREATE TABLE Categories (
CategoryID VARCHAR (6) PRIMARY KEY,
CategoryName VARCHAR(200) NOT NULL,
Description TEXT,
CreatedDate DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE Publisher (
PublisherID VARCHAR(6) PRIMARY KEY,
PublisherName VARCHAR(200) NOT NULL
) ENGINE=InnoDB;


CREATE TABLE Book_Author (
    AuthorID VARCHAR(6),
    AuthorName VARCHAR(200),
    Summary VARCHAR(200),
    PRIMARY KEY (AuthorID)
);

CREATE TABLE User_Account (
    UserID VARCHAR(6),
    FullName VARCHAR(200),
    Role VARCHAR(20),
    Phone VARCHAR(20),
    Email VARCHAR(200),
    Password VARCHAR(200),
    City VARCHAR(300),
    Ward VARCHAR(300),
    Street VARCHAR(300),
    HouseNumber VARCHAR(300),
    District VARCHAR(300),
    CreatedDate DATETIME,
    Status BIT,
    Points INT,
    Username VARCHAR(200),
    PRIMARY KEY (UserID)
);



CREATE TABLE Product (
  ProductID VARCHAR(6) PRIMARY KEY,
  ProductName VARCHAR(255) NOT NULL,
  Description TEXT,
  Price DECIMAL(10,2) NOT NULL,
  Image LONGBLOB NULL,
  ImageUrl VARCHAR(1000) NULL,
  PublisherID VARCHAR(6) DEFAULT NULL,
  AuthorID VARCHAR(6) DEFAULT NULL,
  SoldQuantity INT DEFAULT 0,
  `Condition` ENUM('New','Used') DEFAULT 'New',
  CreatedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
  Status BIT,
  CONSTRAINT fk_product_publisher
    FOREIGN KEY (PublisherID)
    REFERENCES Publisher(PublisherID)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT fk_product_author
    FOREIGN KEY (AuthorID)
    REFERENCES Book_Author(AuthorID)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB;



CREATE TABLE Product_Categories (
ProductID VARCHAR (6) NOT NULL,
CategoryID VARCHAR(6) NOT NULL,
PRIMARY KEY (ProductID, CategoryID),
CONSTRAINT fk_pc_product
FOREIGN KEY (ProductID) REFERENCES Product(ProductID)
ON DELETE CASCADE
ON UPDATE CASCADE,
CONSTRAINT fk_pc_category
FOREIGN KEY (CategoryID) REFERENCES Categories(CategoryID)
ON DELETE CASCADE
ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE SKU (
    SKUID VARCHAR(6),
    ProductID VARCHAR(6),
    Format VARCHAR(50),
    ISBN VARCHAR(50),
    BuyPrice DECIMAL (18,2),
    SellPrice DECIMAL (18,2),
        Stock INT,
    Status BIT,
    PRIMARY KEY (SKUID),
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID),
UNIQUE KEY uniq_product_sku (ProductID, SKUID)
);

CREATE TABLE PRODUCT_SALE (
    ProductSaleID VARCHAR(6),
    SKUID VARCHAR(6),
    DiscountedPrice DECIMAL(18,2),
    StartDate DATETIME,
    EndDate DATETIME,
    PRIMARY KEY (ProductSaleID),
    FOREIGN KEY (SKUID) REFERENCES SKU(SKUID)
);

CREATE TABLE Blog (
    BlogID INT AUTO_INCREMENT,
    Title VARCHAR(300),
    Content TEXT,
    Thumbnail VARCHAR(300),
    CreatedDate DATETIME,
    UserID VARCHAR(6),
    Section VARCHAR(50) NOT NULL DEFAULT '',
    PRIMARY KEY (BlogID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);

CREATE TABLE Voucher (
    VoucherID VARCHAR(6),
    VoucherName VARCHAR(100),
    Code VARCHAR(50),
    Description VARCHAR(200),
    DiscountType VARCHAR(20),
    DiscountValue DECIMAL(18,2),
    MinOrder DECIMAL(18,2),
    MaxDiscount DECIMAL(18,2),
    StartDate DATETIME,
    EndDate DATETIME,
    UsageLimit INT,
    UsedCount INT,
    VoucherPoint INT,
    Status BIT,
    RankRequirement VARCHAR(20),
    PRIMARY KEY (VoucherID)
);

CREATE TABLE Cart (
    CartID VARCHAR(6),
    UserID VARCHAR(6),
    PRIMARY KEY (CartID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);

CREATE TABLE Cart_Items (
    CartItemID INT AUTO_INCREMENT,
    CartID VARCHAR(6),
    SKU_ID VARCHAR(6),
    Quantity INT,
    UnitPrice DECIMAL(18,2),
    DiscountedPrice DECIMAL(18,2),
    TotalPrice DECIMAL(18,2),
    Note VARCHAR(200),
    PRIMARY KEY (CartItemID),
    FOREIGN KEY (CartID) REFERENCES Cart(CartID),
    FOREIGN KEY (SKU_ID) REFERENCES SKU(SKUID)
);

CREATE TABLE `Order` (
    OrderID VARCHAR(6),
    UserID VARCHAR(6),
    TotalAmount DECIMAL(18,2),
    TotalAmountAfterVoucher DECIMAL(18,2),
    Status VARCHAR(20),
    PaymentMethod VARCHAR(20),
    PaymentStatus VARCHAR(20),
    ShippingCity VARCHAR(200),
    ShippingDistrict VARCHAR(200),
    ShippingWard VARCHAR(200),
    ShippingStreet VARCHAR(200),
    ShippingNumber VARCHAR(100),
    CreatedDate DATETIME,
    DateReceived DATETIME,
    Note VARCHAR(600),
    PRIMARY KEY (OrderID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);

CREATE TABLE Order_Items (
    OrderItemID INT AUTO_INCREMENT,
    OrderID VARCHAR(6),
    SKU_ID VARCHAR(6),
    Quantity INT,
    UnitPrice DECIMAL(18,2),
    DiscountedPrice DECIMAL(18,2),
    TotalPrice DECIMAL(18,2),
    PRIMARY KEY (OrderItemID),
    FOREIGN KEY (OrderID) REFERENCES `Order`(OrderID),
    FOREIGN KEY (SKU_ID) REFERENCES SKU(SKUID)
);



CREATE TABLE Review (
    ReviewID INT AUTO_INCREMENT,
    ProductID VARCHAR(6),
    UserID VARCHAR(6),
    Rating INT,
    Comment TEXT,
    CreatedDate DATETIME,
    PRIMARY KEY (ReviewID),
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);

CREATE TABLE Point_History (
    PointID INT AUTO_INCREMENT,
    UserID VARCHAR(6),
    PointChange INT,
    Reason VARCHAR(200),
    CreatedDate DATETIME,
    PRIMARY KEY (PointID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);

CREATE TABLE Forum_Topic (
  TopicID INT UNSIGNED AUTO_INCREMENT,
    UserID VARCHAR(6),
    Title VARCHAR(300),
    Description TEXT,
    CreatedBy VARCHAR(6),
    CreatedDate DATETIME,
    IsLocked BIT,
    PRIMARY KEY (TopicID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);

CREATE TABLE Forum_Post (
    PostID INT UNSIGNED AUTO_INCREMENT,
    TopicID INT UNSIGNED,
    UserID VARCHAR(6),
    Content VARCHAR(300),
    CreatedDate DATETIME,
    PRIMARY KEY (PostID),
    FOREIGN KEY (TopicID) REFERENCES Forum_Topic(TopicID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
) ENGINE=InnoDB;

CREATE TABLE Forum_Comment (
    CommentID INT AUTO_INCREMENT,
    PostID INT UNSIGNED,
    UserID VARCHAR(6),
    Content TEXT,
    CreatedDate DATETIME,
    PRIMARY KEY (CommentID),
    FOREIGN KEY (PostID) REFERENCES Forum_Post(PostID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);

CREATE TABLE Contact_Message (
    MessageID INT AUTO_INCREMENT,
    UserID VARCHAR(6),
    FullName VARCHAR(200),
    Email VARCHAR(200),
    Subject VARCHAR(200),
    Message VARCHAR(200),
    CreatedDate DATETIME,
    Status VARCHAR(50),
    PRIMARY KEY (MessageID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID)
);


CREATE TABLE Returns_Order (
    ReturnID VARCHAR(6),
    OrderID VARCHAR(6),
    Status VARCHAR(50),
    TotalRefund DECIMAL(18,2),
    CreatedDate DATETIME,
    PRIMARY KEY (ReturnID),
    FOREIGN KEY (OrderID) REFERENCES `Order`(OrderID)
);

CREATE TABLE Return_Items (
    ReturnItemID INT AUTO_INCREMENT,
    ReturnID VARCHAR(6),
    Quantity INT,
    RefundAmount DECIMAL(18,2),
    Reason VARCHAR(300),
    OrderItemID INT,
    PRIMARY KEY (ReturnItemID),
    FOREIGN KEY (ReturnID) REFERENCES Returns_Order(ReturnID),
    FOREIGN KEY (OrderItemID) REFERENCES Order_Items(OrderItemID)
);

CREATE TABLE Return_Images (
    ImageID VARCHAR(6) PRIMARY KEY,
    ReturnItemID INT(11),
    ImageURL VARCHAR(1000),
    FOREIGN KEY (ReturnItemID) REFERENCES Return_Items(ReturnItemID)
);

CREATE TABLE Carrier (
    CarrierID VARCHAR(6),
    CarrierName VARCHAR(200),
ShippingPrice DECIMAL(12,0),
    Phone VARCHAR(20),
    Website VARCHAR(200),
    PRIMARY KEY (CarrierID)
);

CREATE TABLE Shipping_Order (
    ShippingID VARCHAR(6),
    OrderID VARCHAR(6),
    ReturnID VARCHAR(6),
    CarrierID VARCHAR(6),
    TrackingNumber VARCHAR(50),
    Status VARCHAR(50),
    ShippedDate DATETIME,
    DeliveredDate DATETIME,
    PRIMARY KEY (ShippingID),
    FOREIGN KEY (OrderID) REFERENCES `Order`(OrderID),
    FOREIGN KEY (CarrierID) REFERENCES Carrier(CarrierID),
    FOREIGN KEY (ReturnID) REFERENCES Returns_Order(ReturnID)
);

CREATE TABLE Banner (
    BannerID INT AUTO_INCREMENT,
    Title VARCHAR(200),
    ImageUrl VARCHAR(300),
    ImageBinary LONGBLOB,
    PRIMARY KEY (BannerID)
);

CREATE TABLE User_Voucher (
    ID VARCHAR(6),
    UserID VARCHAR(6),
    VoucherID VARCHAR(6),
    OrderID VARCHAR(6),
    DateReceived DATETIME,
    PRIMARY KEY (ID),
    FOREIGN KEY (UserID) REFERENCES User_Account(UserID),
    FOREIGN KEY (VoucherID) REFERENCES Voucher(VoucherID),
    FOREIGN KEY (OrderID) REFERENCES `Order`(OrderID)
);

CREATE TABLE Payment (
    PaymentID VARCHAR(6),
    OrderID VARCHAR(6),
    Amount DECIMAL(18,2),
    Method VARCHAR(20),
    TransactionRef VARCHAR(100),
    Status VARCHAR(20),
    PaidDate DATETIME,
    PRIMARY KEY (PaymentID),
    FOREIGN KEY (OrderID) REFERENCES `Order`(OrderID)
);

CREATE TABLE COMPANY (
    CompanyID INT AUTO_INCREMENT PRIMARY KEY,
    CompanyName VARCHAR(255) NOT NULL,
    TaxCode VARCHAR(50) NOT NULL UNIQUE,
    HouseNumber VARCHAR(50) NOT NULL,
    StreetName VARCHAR(255) NOT NULL,
    WardName VARCHAR(255) NOT NULL,
    DistrictName VARCHAR(255) NOT NULL,
    CityName VARCHAR(255) NOT NULL
);


-- ===== INSERT TEST CARRIERS (BẢNG Carrier) =====
-- Schema: Carrier(CarrierID, CarrierName, ShippingPrice, Phone, Website)

INSERT INTO Carrier (CarrierID, CarrierName, ShippingPrice, Phone, Website) VALUES
('C00001', 'Giao Hàng Nhanh (GHN)', 25000, '1900636777', 'https://ghn.vn'),
('C00002', 'Giao Hàng Tiết Kiệm (GHTK)', 20000, '19006092', 'https://ghtk.vn'),
('C00003', 'Viettel Post', 30000, '19008095', 'https://viettelpost.com.vn'),
('C00004', 'J&T Express', 28000, '19001088', 'https://jtexpress.vn');

-- Nếu sợ trùng khóa (CarrierID đã có) thì xài kiểu này:
-- INSERT INTO Carrier (CarrierID, CarrierName, ShippingPrice, Phone, Website) VALUES
-- ('C00001','Giao Hàng Nhanh (GHN)',25000,'1900636777','https://ghn.vn')
-- ON DUPLICATE KEY UPDATE
-- CarrierName=VALUES(CarrierName), ShippingPrice=VALUES(ShippingPrice),
-- Phone=VALUES(Phone), Website=VALUES(Website);


SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- =========================
-- 1. PUBLISHER
-- =========================
INSERT INTO Publisher (PublisherID, PublisherName) VALUES
('PB01','Nhà Xuất Bản Kim Đồng'),
('PB02','Nhà Xuất Bản Hội Nhà Văn'),
('PB03','Nhà Xuất Bản Hà Nội'),
('PB04','Nhà Xuất Bản Hồng Đức'),
('PB05','Nhà Xuất Bản Thế Giới'),
('PB06','Nhà Xuất Bản Thanh Niên'),
('PB07','Nhà Xuất Bản Giáo Dục Việt Nam'),
('PB08','Marvel Comics'),
('PB09','Nhà Xuất Bản Lao Động'),
('PB10','ĐHQG Hà Nội'),
('PB11','Nhà Xuất Bản Tổng Hợp TP.HCM'),
('PB12','Nhà Xuất Bản Văn Học'),
('PB13','Nhà Xuất Bản Thể Thao Và Du Lịch'),
('PB14','Nhà Xuất Bản Chính Trị Quốc Gia Sự Thật'),
('PB15','Nhà Xuất Bản Phụ Nữ')
ON DUPLICATE KEY UPDATE PublisherName = VALUES(PublisherName);

-- =========================
-- 2. CATEGORIES 
-- =========================
INSERT INTO Categories (CategoryID, CategoryName, Description) VALUES
('C00001','Thiếu Nhi','Sách thiếu nhi'),
('C00002','Văn Học','Văn học'),
('C00003','Khoa Học','Khoa học'),
('C00004','Trinh Thám','Trinh thám'),
('C00005','Nấu Ăn','Ẩm thực'),
('C00006','Tử Vi','Tử vi – phong thủy'),
('C00007','Nghệ Thuật','Nghệ thuật'),
('C00008','Sức Khỏe','Sức khỏe'),
('C00009','Giáo Khoa','Giáo khoa'),
('C00010','Giả Tưởng','Giả tưởng'),
('C00011','Pháp Luật','Pháp luật'),
('C00012','Kinh Tế','Kinh tế'),
('C00013','CNTT','Công nghệ thông tin'),
('C00014','Đời Sống','Đời sống'),
('C00015','Ngôn Tình','Ngôn tình'),
('C00016','Thể Thao','Thể thao'),
('C00017','Chính Trị','Chính trị'),
('C00018','Văn Hóa','Văn hóa'),
('C00019','Tiểu Thuyết','Tiểu thuyết'),
('C00020','Thơ','Thơ');

-- =========================
-- 3. PRODUCT (20 CUỐN)
-- =========================
INSERT INTO Product
(ProductID, ProductName, Description, Price, Image, ImageUrl, PublisherID, SoldQuantity, `Condition`, Status)
VALUES
('P01','Nobita Và Cuộc Phiêu Lưu Vào Thế Giới Trong Tranh','Truyện thiếu nhi Doraemon',60000,NULL,'https://filebroker-cdn.lazada.vn/kf/S7b0dfaaf829342ad8d828e30ab388a8eS.jpg','PB01',0,'New',1),
('P02','Những Ngày Thơ Ấu','Danh tác văn học Việt Nam',75000,NULL,'https://filebroker-cdn.lazada.vn/kf/Sddff86210c9f402a99786fd6baea6e2b0.jpg','PB02',0,'New',1),
('P03','Khoa Học Khắp Quanh Ta','Khoa học thiếu nhi',50000,NULL,'https://filebroker-cdn.lazada.vn/kf/Sd192995b2a8b4d8386a46a4718ca58d0S.jpg','PB03',0,'New',1),
('P04','Bạch Dạ Hành','Tiểu thuyết trinh thám',200000,NULL,'https://filebroker-cdn.lazada.vn/kf/S76530db347854d0ca49351d45b413a3a7.jpg','PB04',0,'New',1),
('P05','Khoa Học Về Nấu Ăn','Ẩm thực khoa học',350000,NULL,'https://filebroker-cdn.lazada.vn/kf/Se4d9defb91c046f499db137fb1880021w.jpg','PB05',0,'New',1),
('P06','Thần Số Học Ứng Dụng','Tử vi ứng dụng',170000,NULL,'https://filebroker-cdn.lazada.vn/kf/Sbd368791dd9e4354a015928927bf8c1dD.jpg','PB06',0,'New',1),
('P07','Để Hiểu Nghệ Thuật','Nhập môn nghệ thuật',289000,NULL,'https://filebroker-cdn.lazada.vn/kf/S09f90b84e7a84bfea561b423dd1c41cfZ.jpg','PB05',0,'New',1),
('P08','Sức Khỏe Trong Tay Bạn','Chăm sóc sức khỏe',80000,NULL,'https://filebroker-cdn.lazada.vn/kf/Sccfd3137ffa5447aa33df5c17f9e97425.jpg','PB05',0,'New',1),
('P09','Toán 12','Sách giáo khoa Toán',20000,NULL,'https://filebroker-cdn.lazada.vn/kf/S3f70e497244a41b78419cf90e585bc63H.jpg','PB07',0,'New',1),
('P10','Star Wars: Jedi’s End','Star Wars giả tưởng',480000,NULL,'https://filebroker-cdn.lazada.vn/kf/S0351b602f32d4f45add7c69f92049ffb9.jpg','PB08',0,'New',1),
('P11','Tinh Thần Pháp Luật','Pháp luật học',190000,NULL,'https://filebroker-cdn.lazada.vn/kf/S9ba079bef00d49ac9fcfdb1b2ec687c0j.jpg','PB05',0,'New',1),
('P12','Marketing Mix','Marketing căn bản',209000,NULL,'https://filebroker-cdn.lazada.vn/kf/Sa581302866354a7f85be571efa18913df.jpg','PB09',0,'New',1),
('P13','Lập Trình Scratch','Lập trình cho trẻ',220000,NULL,'https://filebroker-cdn.lazada.vn/kf/Se031ee73e93247bab6d2489d91448662j.jpg','PB10',0,'New',1),
('P14','Muôn Kiếp Nhân Sinh','Đời sống – chiêm nghiệm',268000,NULL,'https://filebroker-cdn.lazada.vn/kf/S6e5da7acef78460392e1df1963c9d7bcf.jpg','PB11',0,'New',1),
('P15','Khó Dỗ Dành','Ngôn tình',205000,NULL,'https://filebroker-cdn.lazada.vn/kf/S0a73d67635204ee0bf30dc042b163d25u.jpg','PB12',0,'New',1),
('P16','Chạy Bộ Để Vượt Qua','Thể thao – chạy bộ',199000,NULL,'https://filebroker-cdn.lazada.vn/kf/Sd6c32f196a9444a1a23e584a9c19917c2.jpg','PB13',0,'New',1),
('P17','Nguồn Gốc Trật Tự Chính Trị','Chính trị học',299000,NULL,'https://filebroker-cdn.lazada.vn/kf/S06b7c2eca3f3440e869c2de5ce4320ee6.jpg','PB05',0,'New',1),
('P18','Văn Minh Trung Hoa','Văn hóa Trung Hoa',506000,NULL,'https://filebroker-cdn.lazada.vn/kf/S5226c6d7f1b444cea442735421cbb731o.jpg','PB14',0,'New',1),
('P19','Sau Này','Tiểu thuyết hiện đại',189000,NULL,'https://filebroker-cdn.lazada.vn/kf/S0d7139121d984dd78b874364a220d78c9.jpg','PB06',0,'New',1),
('P20','An','Thơ hiện đại',85000,NULL,'https://filebroker-cdn.lazada.vn/kf/S3a815393d88f4a85a4175c0c5862d6ceS.jpg','PB15',0,'New',1);

-- =========================
-- 4. PRODUCT_CATEGORIES
-- =========================
INSERT INTO Product_Categories VALUES
('P01','C00001'),('P02','C00002'),('P03','C00003'),('P04','C00004'),('P05','C00005'),
('P06','C00006'),('P07','C00007'),('P08','C00008'),('P09','C00009'),('P10','C00010'),
('P11','C00011'),('P12','C00012'),('P13','C00013'),('P14','C00014'),('P15','C00015'),
('P16','C00016'),('P17','C00017'),('P18','C00018'),('P19','C00019'),('P20','C00020');

-- =========================
-- 5. SKU (MỖI PRODUCT 2 SKU) - INSERT THẲNG, KHÔNG CONCAT/SELECT
-- =========================
INSERT INTO SKU (SKUID, ProductID, Format, ISBN, BuyPrice, SellPrice, Stock, Status) VALUES
('SK0001','P01','Bìa mềm','978-604-000001', 36000,  60000,100,1),
('SK0002','P01','Bìa cứng','978-604-000002', 48000,  80000,100,1),

('SK0003','P02','Bìa mềm','978-604-000003', 45000,  75000,100,1),
('SK0004','P02','Bìa cứng','978-604-000004', 57000,  95000,100,1),

('SK0005','P03','Bìa mềm','978-604-000005', 30000,  50000,100,1),
('SK0006','P03','Bìa cứng','978-604-000006', 42000,  70000,100,1),

('SK0007','P04','Bìa mềm','978-604-000007',120000, 200000,100,1),
('SK0008','P04','Bìa cứng','978-604-000008',132000, 220000,100,1),

('SK0009','P05','Bìa mềm','978-604-000009',210000, 350000,100,1),
('SK0010','P05','Bìa cứng','978-604-000010',222000, 370000,100,1),

('SK0011','P06','Bìa mềm','978-604-000011',102000, 170000,100,1),
('SK0012','P06','Bìa cứng','978-604-000012',114000, 190000,100,1),

('SK0013','P07','Bìa mềm','978-604-000013',173400, 289000,100,1),
('SK0014','P07','Bìa cứng','978-604-000014',185400, 309000,100,1),

('SK0015','P08','Bìa mềm','978-604-000015', 48000,  80000,100,1),
('SK0016','P08','Bìa cứng','978-604-000016', 60000, 100000,100,1),

('SK0017','P09','Bìa mềm','978-604-000017', 12000,  20000,100,1),
('SK0018','P09','Bìa cứng','978-604-000018', 24000,  40000,100,1),

('SK0019','P10','Bìa mềm','978-604-000019',288000, 480000,100,1),
('SK0020','P10','Bìa cứng','978-604-000020',300000, 500000,100,1),

('SK0021','P11','Bìa mềm','978-604-000021',114000, 190000,100,1),
('SK0022','P11','Bìa cứng','978-604-000022',126000, 210000,100,1),

('SK0023','P12','Bìa mềm','978-604-000023',125400, 209000,100,1),
('SK0024','P12','Bìa cứng','978-604-000024',137400, 229000,100,1),

('SK0025','P13','Bìa mềm','978-604-000025',132000, 220000,100,1),
('SK0026','P13','Bìa cứng','978-604-000026',144000, 240000,100,1),

('SK0027','P14','Bìa mềm','978-604-000027',160800, 268000,100,1),
('SK0028','P14','Bìa cứng','978-604-000028',172800, 288000,100,1),

('SK0029','P15','Bìa mềm','978-604-000029',123000, 205000,100,1),
('SK0030','P15','Bìa cứng','978-604-000030',135000, 225000,100,1),

('SK0031','P16','Bìa mềm','978-604-000031',119400, 199000,100,1),
('SK0032','P16','Bìa cứng','978-604-000032',131400, 219000,100,1),

('SK0033','P17','Bìa mềm','978-604-000033',179400, 299000,100,1),
('SK0034','P17','Bìa cứng','978-604-000034',191400, 319000,100,1),

('SK0035','P18','Bìa mềm','978-604-000035',303600, 506000,100,1),
('SK0036','P18','Bìa cứng','978-604-000036',315600, 526000,100,1),

('SK0037','P19','Bìa mềm','978-604-000037',113400, 189000,100,1),
('SK0038','P19','Bìa cứng','978-604-000038',125400, 209000,100,1),

('SK0039','P20','Bìa mềm','978-604-000039', 51000,  85000,100,1),
('SK0040','P20','Bìa cứng','978-604-000040', 63000, 105000,100,1);

-- =========================
-- 6. PRODUCT_SALE - GIÁ SALE NHẬP TAY (bạn đưa sao tui để y vậy)
-- =========================
INSERT INTO PRODUCT_SALE (ProductSaleID, SKUID, DiscountedPrice, StartDate, EndDate)
VALUES
('PS0001', 'SK0001', 45000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
('PS0002', 'SK0002', 60000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
('PS0003', 'SK0003',  49000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
('PS0004', 'SK0004',  79000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
('PS0005', 'SK0005',  59000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
('PS0006', 'SK0006',  55000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
('PS0007', 'SK0007',  50000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
('PS0008', 'SK0008',  75000.00, NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY));

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;


