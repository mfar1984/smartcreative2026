# Requirements Document

## Introduction

This document specifies the requirements for the official website of Smart Digital Creative Management & Resources company. The website will serve as the company's digital presence, showcasing services, portfolio, and providing contact information. The implementation follows a phased approach with the Home page fully functional in Phase 1, while other pages display maintenance placeholders.

## Glossary

- **Website**: The Smart Digital Creative Management & Resources public-facing web application
- **Laravel**: The PHP web application framework used for backend development
- **Hero_Section**: A prominent visual banner area at the top of each page
- **Navigation_Menu**: The main menu system for site navigation
- **Maintenance_Page**: A placeholder page indicating content is under development
- **Responsive_Design**: Web design approach ensuring proper display across different device sizes
- **Sub_Menu**: Dropdown menu items under a parent navigation item
- **Top_Header**: The uppermost section of the page displaying date/time and contact information
- **Footer**: The bottom section of the page organized into four columns
- **Home_Page**: The main landing page of the website

## Requirements

### Requirement 1: Website Technology Stack

**User Story:** As a developer, I want to use Laravel framework for the website, so that I can leverage a robust PHP framework for building the public website.

#### Acceptance Criteria

1. THE Website SHALL be built using the Laravel PHP framework
2. THE Website SHALL serve static and dynamic content through Laravel routing
3. THE Website SHALL use Laravel's Blade templating engine for views

### Requirement 2: Professional Design and Branding

**User Story:** As a business owner, I want a professional website design with proper branding, so that visitors perceive our company as credible and trustworthy.

#### Acceptance Criteria

1. THE Website SHALL display the company name "Smart Digital Creative Management & Resources"
2. THE Website SHALL display the company registration number "202303326459 / 003562257-U"
3. THE Website SHALL display the company address "Suite: 33-01, 33rd Floor, Menara Keck Seng, 203 Jalan Bukit Bintang, 55100 Kuala Lumpur, Malaysia"
4. THE Website SHALL use a white background as the primary background color
5. THE Website SHALL maintain a professional and official visual appearance
6. THE Website SHALL display the company logo in the header

### Requirement 3: Top Header Section

**User Story:** As a visitor, I want to see contact information and current date/time at the top of the page, so that I can quickly access contact details and see the current time.

#### Acceptance Criteria

1. THE Website SHALL display a top header section above the main navigation on all pages
2. THE Top_Header SHALL display the current date and time on the left side in the format "Day, DD Month YYYY • HH:MM:SS am/pm"
3. THE Top_Header SHALL update the time display dynamically every second
4. THE Top_Header SHALL display an email icon followed by a clickable email link on the right side
5. THE Top_Header SHALL display a telephone icon followed by a telephone number on the right side
6. THE Top_Header SHALL separate the email and telephone with a bullet point separator

### Requirement 4: Main Navigation Menu

**User Story:** As a visitor, I want to see a clear navigation menu with logo, so that I can easily identify the company and navigate the website.

#### Acceptance Criteria

1. THE Website SHALL display a main navigation menu section below the top header on all pages
2. THE Website SHALL position the company logo on the left side of the navigation menu
3. THE Website SHALL position the Navigation_Menu on the right side of the navigation menu
4. THE Navigation_Menu SHALL include a "Home" menu item
5. THE Navigation_Menu SHALL include a "Services" menu item with Sub_Menu items
6. THE Navigation_Menu SHALL include a "Registration" menu item
7. THE Navigation_Menu SHALL include a "Portfolio" menu item
8. THE Navigation_Menu SHALL include a "Shop" menu item
9. THE Navigation_Menu SHALL include a "Contact" menu item
10. WHEN a user hovers over or clicks the "Services" menu item, THE Website SHALL display Sub_Menu items: "Event Management", "Online Registration Solutions", and "Digital Creative Solutions"
11. THE Navigation_Menu SHALL display items in the following order: Home, Services, Registration, Portfolio, Shop, Contact

### Requirement 5: Page Content Structure for Home Page

**User Story:** As a visitor, I want to see comprehensive information about the company on the Home page, so that I can understand the company's mission, vision, values, and services.

#### Acceptance Criteria

1. THE Home_Page SHALL include a Hero_Section as the first content section
2. THE Home_Page SHALL include an "About Us" section after the Hero_Section
3. THE Home_Page SHALL include an "Our Mission" section
4. THE Home_Page SHALL include an "Our Vision" section
5. THE Home_Page SHALL include an "Our Values" section
6. THE Home_Page SHALL include an "Our Objectives" section
7. THE Home_Page SHALL include an "Our Services" section displaying three service cards
8. THE Home_Page SHALL display sections in the following order: Hero Page, About Us, Our Mission, Our Vision, Our Values, Our Objectives, Our Services

### Requirement 6: Footer Structure

**User Story:** As a visitor, I want to see organized footer information, so that I can easily find company details, navigation links, legal information, and social media connections.

#### Acceptance Criteria

1. THE Website SHALL display a footer section at the bottom of all pages
2. THE Footer SHALL be organized into four columns
3. THE Footer Column_1 SHALL display the company name, registration number, and full address
4. THE Footer Column_2 SHALL display quick links to main pages (Home, Services, Registration, Portfolio, Shop, Contact)
5. THE Footer Column_3 SHALL display legal links including "Privacy Policy" and "Terms of Service"
6. THE Footer Column_4 SHALL display social media links and contact information under the heading "Connect With Us"
7. THE Footer SHALL adapt to a stacked layout on mobile devices

### Requirement 7: Page Structure and Routing

**User Story:** As a visitor, I want each menu item to navigate to a separate page, so that I can access specific content through distinct URLs.

#### Acceptance Criteria

1. THE Website SHALL create a separate route and page for the Home menu item
2. THE Website SHALL create a separate route and page for the Services menu item
3. THE Website SHALL create separate routes and pages for each Sub_Menu item under Services
4. THE Website SHALL create a separate route and page for the Registration menu item
5. THE Website SHALL create a separate route and page for the Portfolio menu item
6. THE Website SHALL create a separate route and page for the Shop menu item
7. THE Website SHALL create a separate route and page for the Contact menu item
8. WHEN a user clicks a Navigation_Menu item, THE Website SHALL navigate to the corresponding page with a unique URL
9. THE Website SHALL display a Hero_Section on every page

### Requirement 8: Responsive Design

**User Story:** As a visitor using any device, I want the website to display properly on my screen, so that I can access content regardless of device type.

#### Acceptance Criteria

1. THE Website SHALL implement Responsive_Design for all pages
2. WHEN viewed on mobile devices, THE Website SHALL adjust layout to fit smaller screens
3. WHEN viewed on tablet devices, THE Website SHALL adjust layout to fit medium screens
4. WHEN viewed on desktop devices, THE Website SHALL display the full layout optimized for larger screens
5. THE Navigation_Menu SHALL adapt to mobile devices with appropriate mobile navigation patterns
6. THE Footer SHALL adapt to a stacked layout on mobile devices

### Requirement 9: Phased Implementation - Home Page

**User Story:** As a business owner, I want the Home page fully developed first, so that visitors can see our primary content while other sections are being completed.

#### Acceptance Criteria

1. THE Website SHALL implement a complete Home page with full design and content
2. THE Home_Page SHALL include a Hero_Section with compelling visual content
3. THE Home_Page SHALL include sections showcasing company information
4. THE Home_Page SHALL include visual elements consistent with the professional design theme
5. THE Home_Page SHALL be fully functional and production-ready

### Requirement 10: Phased Implementation - Maintenance Pages

**User Story:** As a business owner, I want placeholder pages for incomplete sections, so that the website can launch with the Home page while other pages are under development.

#### Acceptance Criteria

1. WHEN a user navigates to the Services page, THE Website SHALL display a Maintenance_Page
2. WHEN a user navigates to any Services Sub_Menu page, THE Website SHALL display a Maintenance_Page
3. WHEN a user navigates to the Portfolio page, THE Website SHALL display a Maintenance_Page
4. WHEN a user navigates to the Shop page, THE Website SHALL display a Maintenance_Page
5. WHEN a user navigates to the Contact page, THE Website SHALL display a Maintenance_Page
6. WHEN a user selects an individual event on the Registration_Page, THE Website SHALL display a Maintenance_Page until the registration form is implemented
7. THE Maintenance_Page SHALL communicate that the section is under development
8. THE Maintenance_Page SHALL maintain the header and navigation structure
9. THE Maintenance_Page SHALL include a Hero_Section consistent with other pages

### Requirement 11: Domain and Deployment

**User Story:** As a business owner, I want the website accessible at our company domain, so that customers can find us at our official web address.

#### Acceptance Criteria

1. THE Website SHALL be configured to serve content at the domain https://smartcreative.my/
2. THE Website SHALL handle HTTPS connections securely
3. THE Website SHALL redirect HTTP requests to HTTPS

### Requirement 12: Registration Page

**User Story:** As a visitor, I want to browse the events that are open for registration, so that I can find an event that interests me and sign up for it.

#### Acceptance Criteria

1. THE Website SHALL display a Registration_Page at the route `/registration`
2. THE Registration_Page SHALL display each available event as an Event_Card
3. THE Event_Card SHALL display the event title, category, date, time and venue
4. THE Event_Card SHALL display a short description of the event
5. THE Event_Card SHALL display the registration fee, or "Free" when no fee applies
6. THE Event_Card SHALL display the number of places remaining out of the total capacity
7. THE Event_Card SHALL display a registration status of "Open for Registration", "Closing Soon", "Fully Booked" or "Registration Closed"
8. WHEN the event status is "Open for Registration" or "Closing Soon", THE Event_Card SHALL display an enabled registration call to action
9. WHEN the event status is "Fully Booked" or "Registration Closed", THE Event_Card SHALL display a disabled registration call to action
10. THE Registration_Page SHALL display Event_Cards in a responsive grid: one column on mobile, two columns on tablet, three columns on desktop
11. WHEN no events are available, THE Registration_Page SHALL display an empty state explaining that no events are currently open
12. THE Registration_Page SHALL maintain the Top_Header, Navigation_Menu and Footer structure
