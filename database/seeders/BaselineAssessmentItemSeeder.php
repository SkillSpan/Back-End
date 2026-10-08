<?php

namespace Database\Seeders;

use App\Models\BaselineAssessmentItem;
use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Prototype baseline question bank.
 *
 * Questions are attached to SKILLS, never directly to career roles: the
 * existing BaselineQuestionSelectionService resolves
 *
 *   career role -> required skills -> questions mapped to those skills
 *
 * so a question only has to be authored once per skill and every role that
 * requires that skill inherits it. This is what makes the assessment
 * "dynamic" — changing the career role (which is itself filtered by
 * specialization) changes the skill set and therefore the questions.
 *
 * Every skill referenced by CareerRoleSeeder has at least one active item
 * here, otherwise selection would raise INSUFFICIENT_QUESTION_COVERAGE for
 * a role whose skill has no question. The keys are the skill slugs produced
 * by SkillSeeder (Str::slug of the skill name) — e.g. "TCP/IP" -> "tcpip",
 * "C/C++" -> "cc".
 *
 * Item ids are "{skillSlug}-001"; updateOrCreate on (assessment_version,
 * item_id) keeps the seed idempotent. Options are the answer choices and
 * correct_answer is one of them — the learner-facing API never exposes
 * correct_answer (see BaselineAssessmentController::transformQuestion).
 */
class BaselineAssessmentItemSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $items = [
            // ---- Backend ----
            'programming-fundamentals' => [
                'What is the main purpose of a variable in a program?',
                [
                    'To store a value that can be reused and changed',
                    'To permanently delete data from memory',
                    'To define the visual style of the application',
                    'To establish a network connection',
                ],
                'To store a value that can be reused and changed',
            ],
            'sql-databases' => [
                'What is the purpose of a primary key in a database table?',
                [
                    'To uniquely identify each record in the table',
                    'To sort records alphabetically',
                    'To allow duplicate rows',
                    'To store the largest value in the table',
                ],
                'To uniquely identify each record in the table',
            ],
            'rest-apis' => [
                'What is the main difference between the GET and POST HTTP methods?',
                [
                    'GET retrieves data, while POST is commonly used to submit or create data',
                    'GET creates data, while POST only reads data',
                    'Both methods behave identically',
                    'GET is only used for authentication',
                ],
                'GET retrieves data, while POST is commonly used to submit or create data',
            ],
            'authentication-authorization' => [
                'What is the difference between authentication and authorization?',
                [
                    'Authentication verifies identity, while authorization determines permissions',
                    'They are two names for the same process',
                    'Authentication grants permissions, while authorization verifies identity',
                    'Both only apply to databases',
                ],
                'Authentication verifies identity, while authorization determines permissions',
            ],
            'backend-architecture' => [
                'What is the role of middleware in a backend application?',
                [
                    'It processes requests between the client and the application logic',
                    'It stores the static images of the application',
                    'It replaces the database',
                    'It renders the user interface',
                ],
                'It processes requests between the client and the application logic',
            ],

            // ---- Data & Analytics ----
            'statistics-fundamentals' => [
                'What is the difference between the mean and the median?',
                [
                    'The mean is the average, while the median is the middle value',
                    'The median is the average, while the mean is the middle value',
                    'They are always identical',
                    'The mean only applies to text data',
                ],
                'The mean is the average, while the median is the middle value',
            ],
            'sql' => [
                'What does the SQL GROUP BY clause do?',
                [
                    'It groups rows that share a value so aggregates can be computed',
                    'It deletes duplicate tables',
                    'It renames a column',
                    'It connects to a remote server',
                ],
                'It groups rows that share a value so aggregates can be computed',
            ],
            'data-cleaning' => [
                'Why is data cleaning important before analysis?',
                [
                    'It removes errors and inconsistencies that would distort results',
                    'It makes the dataset smaller by deleting all rows',
                    'It encrypts the data',
                    'It is only required for text data',
                ],
                'It removes errors and inconsistencies that would distort results',
            ],
            'data-visualization' => [
                'Why is data visualization useful?',
                [
                    'It makes patterns and trends easier to understand',
                    'It permanently stores raw data',
                    'It replaces the need for data cleaning',
                    'It guarantees that the data is correct',
                ],
                'It makes patterns and trends easier to understand',
            ],
            'data-analysis' => [
                'What is the difference between correlation and causation?',
                [
                    'Correlation shows a relationship, while causation means one thing causes another',
                    'They mean exactly the same thing',
                    'Causation is only about numbers',
                    'Correlation proves causation',
                ],
                'Correlation shows a relationship, while causation means one thing causes another',
            ],

            // ---- Machine Learning & AI ----
            'python' => [
                'Why is Python commonly used in AI and data work?',
                [
                    'It has a rich ecosystem of data and machine-learning libraries',
                    'It is the only language that supports loops',
                    'It cannot be used for scripting',
                    'It compiles directly to machine code only',
                ],
                'It has a rich ecosystem of data and machine-learning libraries',
            ],
            'machine-learning' => [
                'What is the difference between supervised and unsupervised learning?',
                [
                    'Supervised learning uses labelled data, while unsupervised learning does not',
                    'Unsupervised learning always uses labels',
                    'Supervised learning never uses data',
                    'They are the same technique',
                ],
                'Supervised learning uses labelled data, while unsupervised learning does not',
            ],
            'neural-networks' => [
                'What is a neural network?',
                [
                    'A set of connected layers of nodes that learn patterns from data',
                    'A database of labelled records',
                    'A type of network cable',
                    'A programming language',
                ],
                'A set of connected layers of nodes that learn patterns from data',
            ],
            'model-evaluation' => [
                'What is overfitting?',
                [
                    'When a model performs well on training data but poorly on new data',
                    'When a model is too simple to learn anything',
                    'When the dataset is empty',
                    'When training is stopped too early',
                ],
                'When a model performs well on training data but poorly on new data',
            ],
            'ai-fundamentals' => [
                'What is the difference between Artificial Intelligence and Machine Learning?',
                [
                    'AI is the broader goal, while Machine Learning is one approach to achieve it',
                    'Machine Learning is broader than AI',
                    'They are unrelated fields',
                    'AI only refers to hardware',
                ],
                'AI is the broader goal, while Machine Learning is one approach to achieve it',
            ],
            'statistics' => [
                'What is an outlier in a dataset?',
                [
                    'A value that differs significantly from the other observations',
                    'The average of all values',
                    'The most frequent value',
                    'The smallest possible number',
                ],
                'A value that differs significantly from the other observations',
            ],
            'feature-engineering' => [
                'What is feature engineering?',
                [
                    'Creating and selecting input variables that improve a model',
                    'Writing the user interface',
                    'Deploying the model to production',
                    'Labelling the dataset',
                ],
                'Creating and selecting input variables that improve a model',
            ],
            'model-training' => [
                'What is the difference between model training and inference?',
                [
                    'Training learns parameters from data, while inference uses the trained model to make predictions',
                    'Inference learns the parameters from data',
                    'They are the same step',
                    'Training only applies to databases',
                ],
                'Training learns parameters from data, while inference uses the trained model to make predictions',
            ],
            'model-deployment' => [
                'What is model deployment?',
                [
                    'Making a trained model available for use in production',
                    'Labelling the training data',
                    'Choosing the number of layers',
                    'Cleaning the dataset',
                ],
                'Making a trained model available for use in production',
            ],
            'model-monitoring' => [
                'Why should a deployed machine-learning model be monitored?',
                [
                    'To detect performance degradation and data drift over time',
                    'To make it train faster',
                    'To reduce the dataset size',
                    'To change the programming language',
                ],
                'To detect performance degradation and data drift over time',
            ],
            'natural-language-processing' => [
                'What is Natural Language Processing?',
                [
                    'A field of AI that enables computers to understand human language',
                    'A database engine',
                    'A network protocol',
                    'A frontend framework',
                ],
                'A field of AI that enables computers to understand human language',
            ],
            'tokenization' => [
                'What is tokenization in Natural Language Processing?',
                [
                    'Splitting text into smaller units such as words or subwords',
                    'Encrypting text for security',
                    'Compressing images',
                    'Sorting a database',
                ],
                'Splitting text into smaller units such as words or subwords',
            ],
            'word-embeddings' => [
                'What is a word embedding?',
                [
                    'A dense numeric vector that represents the meaning of a word',
                    'A type of database index',
                    'A network address',
                    'A CSS rule',
                ],
                'A dense numeric vector that represents the meaning of a word',
            ],
            'tf-idf' => [
                'What does TF-IDF measure?',
                [
                    'How important a word is to a document relative to a collection of documents',
                    'The speed of a network',
                    'The size of a database',
                    'The colour of a web page',
                ],
                'How important a word is to a document relative to a collection of documents',
            ],
            'text-classification' => [
                'What is sentiment analysis an example of?',
                [
                    'Text classification',
                    'Image segmentation',
                    'Database indexing',
                    'Network routing',
                ],
                'Text classification',
            ],

            // ---- Frontend ----
            'html' => [
                'What is the main purpose of HTML?',
                [
                    'To define the structure and content of a web page',
                    'To style a web page',
                    'To store data in a database',
                    'To run server-side logic',
                ],
                'To define the structure and content of a web page',
            ],
            'css' => [
                'What is the main purpose of CSS?',
                [
                    'To control the presentation and layout of a web page',
                    'To define the data model of a page',
                    'To handle HTTP requests',
                    'To store user credentials',
                ],
                'To control the presentation and layout of a web page',
            ],
            'javascript' => [
                'What is the difference between let and const in JavaScript?',
                [
                    'let allows reassignment, while const does not',
                    'const allows reassignment, while let does not',
                    'They are identical',
                    'Both are only used for styling',
                ],
                'let allows reassignment, while const does not',
            ],
            'responsive-design' => [
                'What does responsive web design mean?',
                [
                    'The layout adapts to different screen sizes and devices',
                    'The page reloads on every click',
                    'The site only works on desktops',
                    'The page uses only images',
                ],
                'The layout adapts to different screen sizes and devices',
            ],
            'frontend-frameworks' => [
                'What is a component in a frontend framework such as React?',
                [
                    'A reusable, self-contained piece of the user interface',
                    'A database table',
                    'A network protocol',
                    'A type of CSS file',
                ],
                'A reusable, self-contained piece of the user interface',
            ],

            // ---- Mobile ----
            'android-development' => [
                'What is the purpose of an Activity in Android?',
                [
                    'It represents a single screen with a user interface',
                    'It stores the application database',
                    'It defines the application icon',
                    'It manages the network hardware of the device',
                ],
                'It represents a single screen with a user interface',
            ],
            'application-lifecycle' => [
                'What does the Android application lifecycle describe?',
                [
                    'The states an application moves through from launch to termination',
                    'The order of database migrations',
                    'The process of uploading to an app store',
                    'The design of the application icon',
                ],
                'The states an application moves through from launch to termination',
            ],
            'ui-components' => [
                'What is RecyclerView used for in Android?',
                [
                    'To efficiently display scrollable lists of items',
                    'To store data locally',
                    'To send HTTP requests',
                    'To define the application theme',
                ],
                'To efficiently display scrollable lists of items',
            ],
            'rest-api-integration' => [
                'Why would a mobile application use a REST API?',
                [
                    'To exchange data with a remote server',
                    'To render HTML on the server',
                    'To compile the application',
                    'To store files on the device only',
                ],
                'To exchange data with a remote server',
            ],
            'local-data-storage' => [
                'Why is local data storage useful in a mobile application?',
                [
                    'It lets the application keep data available offline',
                    'It replaces the need for a backend',
                    'It increases the screen resolution',
                    'It removes the need for user login',
                ],
                'It lets the application keep data available offline',
            ],

            // ---- IT & Operating Systems ----
            'computer-hardware' => [
                'What is the difference between RAM and storage?',
                [
                    'RAM is volatile working memory, while storage keeps data persistently',
                    'Storage is volatile, while RAM is persistent',
                    'They are the same component',
                    'RAM is used only for networking',
                ],
                'RAM is volatile working memory, while storage keeps data persistently',
            ],
            'operating-systems' => [
                'What is the main purpose of an operating system?',
                [
                    'To manage hardware and provide services for applications',
                    'To browse the internet',
                    'To design web pages',
                    'To store backups only',
                ],
                'To manage hardware and provide services for applications',
            ],
            'servers' => [
                'What is a server?',
                [
                    'A computer that provides services or resources to other computers',
                    'A type of printer',
                    'A mobile application',
                    'A database row',
                ],
                'A computer that provides services or resources to other computers',
            ],
            'user-permission-management' => [
                'What is the difference between a normal user and an administrator?',
                [
                    'An administrator has elevated privileges to manage the system',
                    'A normal user can change any system setting',
                    'They have identical permissions',
                    'Administrators cannot log in',
                ],
                'An administrator has elevated privileges to manage the system',
            ],
            'backup-recovery' => [
                'Why are backups important?',
                [
                    'They allow data to be restored after loss or failure',
                    'They make the system run faster',
                    'They replace antivirus software',
                    'They are only needed once',
                ],
                'They allow data to be restored after loss or failure',
            ],
            'system-monitoring' => [
                'What is system monitoring?',
                [
                    'Continuously observing system health and performance',
                    'Deleting old files automatically',
                    'Designing the user interface',
                    'Writing database queries',
                ],
                'Continuously observing system health and performance',
            ],
            'troubleshooting' => [
                'What should you check first when a computer cannot access the internet?',
                [
                    'The physical connection and network settings',
                    'The colour scheme of the desktop',
                    'The size of the hard drive',
                    'The installed fonts',
                ],
                'The physical connection and network settings',
            ],

            // ---- Networking ----
            'networking-fundamentals' => [
                'What is DHCP used for?',
                [
                    'To automatically assign IP addresses to devices on a network',
                    'To encrypt web traffic',
                    'To resolve domain names',
                    'To block malicious traffic',
                ],
                'To automatically assign IP addresses to devices on a network',
            ],
            'internet-fundamentals' => [
                'What is DNS used for?',
                [
                    'To translate domain names into IP addresses',
                    'To assign IP addresses automatically',
                    'To encrypt files',
                    'To compress images',
                ],
                'To translate domain names into IP addresses',
            ],
            'tcpip' => [
                'What is the difference between TCP and UDP?',
                [
                    'TCP is connection-oriented and reliable, while UDP is connectionless',
                    'UDP guarantees delivery, while TCP does not',
                    'They are the same protocol',
                    'TCP only works over Wi-Fi',
                ],
                'TCP is connection-oriented and reliable, while UDP is connectionless',
            ],
            'ip-addressing' => [
                'What is an IP address?',
                [
                    'A unique numeric identifier for a device on a network',
                    'A type of network cable',
                    'A web page address',
                    'A password for a router',
                ],
                'A unique numeric identifier for a device on a network',
            ],
            'routing-switching' => [
                'What is the difference between a switch and a router?',
                [
                    'A switch connects devices within a network, while a router connects different networks',
                    'A router connects devices within a network only',
                    'They perform identical functions',
                    'A switch assigns IP addresses',
                ],
                'A switch connects devices within a network, while a router connects different networks',
            ],
            'network-security' => [
                'What is the purpose of a firewall?',
                [
                    'To control and filter network traffic based on rules',
                    'To assign IP addresses',
                    'To store backups',
                    'To resolve domain names',
                ],
                'To control and filter network traffic based on rules',
            ],
            'network-troubleshooting' => [
                'Which command is commonly used to test connectivity to another host?',
                [
                    'ping',
                    'format',
                    'mkdir',
                    'chmod',
                ],
                'ping',
            ],

            // ---- Business & Systems Analysis ----
            'requirements-analysis' => [
                'What is the difference between functional and non-functional requirements?',
                [
                    'Functional requirements describe what the system does, non-functional describe how well it does it',
                    'Non-functional requirements describe what the system does',
                    'They are the same',
                    'Functional requirements are always optional',
                ],
                'Functional requirements describe what the system does, non-functional describe how well it does it',
            ],
            'stakeholder-analysis' => [
                'Who is a stakeholder in a project?',
                [
                    'Anyone affected by or interested in the project outcome',
                    'Only the software developers',
                    'Only the end users',
                    'Only the project manager',
                ],
                'Anyone affected by or interested in the project outcome',
            ],
            'business-requirements' => [
                'What is a business requirement?',
                [
                    'A high-level statement of what the business needs to achieve',
                    'A line of source code',
                    'A network configuration',
                    'A database index',
                ],
                'A high-level statement of what the business needs to achieve',
            ],
            'use-cases' => [
                'What is a use case?',
                [
                    'A description of how a user interacts with a system to achieve a goal',
                    'A type of database',
                    'A programming language',
                    'A server configuration',
                ],
                'A description of how a user interacts with a system to achieve a goal',
            ],
            'process-analysis' => [
                'What is the goal of process analysis?',
                [
                    'To understand and improve how business processes work',
                    'To design the database schema',
                    'To write the user interface',
                    'To configure the network',
                ],
                'To understand and improve how business processes work',
            ],
            'system-analysis' => [
                'What is system analysis?',
                [
                    'Studying a system to understand and improve its behaviour',
                    'Writing marketing content',
                    'Designing a logo',
                    'Installing hardware drivers',
                ],
                'Studying a system to understand and improve its behaviour',
            ],
            'requirements-engineering' => [
                'What is the difference between a business requirement and a system requirement?',
                [
                    'Business requirements describe needs, while system requirements describe how the system will meet them',
                    'System requirements are written by users only',
                    'They are identical',
                    'Business requirements are always technical',
                ],
                'Business requirements describe needs, while system requirements describe how the system will meet them',
            ],
            'uml' => [
                'What is UML used for?',
                [
                    'Modelling and documenting software system designs',
                    'Storing application data',
                    'Sending network packets',
                    'Styling web pages',
                ],
                'Modelling and documenting software system designs',
            ],
            'data-flow' => [
                'What does a data flow diagram represent?',
                [
                    'How data moves through a system and its processes',
                    'The visual design of a website',
                    'The physical layout of servers',
                    'The project budget',
                ],
                'How data moves through a system and its processes',
            ],
            'system-documentation' => [
                'Why is system documentation important?',
                [
                    'It helps teams understand, maintain and support the system',
                    'It makes the system run faster',
                    'It replaces testing',
                    'It is only needed for marketing',
                ],
                'It helps teams understand, maintain and support the system',
            ],

            // ---- Cybersecurity ----
            'cybersecurity-fundamentals' => [
                'What is phishing?',
                [
                    'A fraudulent attempt to obtain sensitive information by impersonation',
                    'A method of encrypting files',
                    'A type of firewall',
                    'A database backup',
                ],
                'A fraudulent attempt to obtain sensitive information by impersonation',
            ],
            'threats-vulnerabilities' => [
                'What is the difference between a threat and a vulnerability?',
                [
                    'A threat is a potential cause of harm, while a vulnerability is a weakness that can be exploited',
                    'A vulnerability is a potential attacker',
                    'They are the same thing',
                    'A threat only exists in hardware',
                ],
                'A threat is a potential cause of harm, while a vulnerability is a weakness that can be exploited',
            ],
            'authentication' => [
                'What is authentication?',
                [
                    'Verifying the identity of a user or system',
                    'Deciding what a user is allowed to do',
                    'Encrypting network traffic',
                    'Backing up data',
                ],
                'Verifying the identity of a user or system',
            ],
            'encryption' => [
                'What is encryption used for?',
                [
                    'Protecting data by converting it into an unreadable form',
                    'Assigning IP addresses',
                    'Compressing images',
                    'Sorting records',
                ],
                'Protecting data by converting it into an unreadable form',
            ],
            'siem' => [
                'What is SIEM?',
                [
                    'A system that collects and analyses security events in real time',
                    'A programming language',
                    'A type of network cable',
                    'A database engine',
                ],
                'A system that collects and analyses security events in real time',
            ],
            'log-analysis' => [
                'Why is log analysis important in security?',
                [
                    'It helps detect suspicious activity and investigate incidents',
                    'It speeds up the network',
                    'It replaces firewalls',
                    'It designs the user interface',
                ],
                'It helps detect suspicious activity and investigate incidents',
            ],
            'security-monitoring' => [
                'What is a security incident?',
                [
                    'An event that threatens the confidentiality, integrity or availability of systems',
                    'A routine software update',
                    'A scheduled backup',
                    'A design meeting',
                ],
                'An event that threatens the confidentiality, integrity or availability of systems',
            ],
            'incident-response' => [
                'What is incident response?',
                [
                    'The process of detecting, containing and recovering from a security incident',
                    'Installing new hardware',
                    'Writing marketing copy',
                    'Designing a database schema',
                ],
                'The process of detecting, containing and recovering from a security incident',
            ],
            'security-events' => [
                'What is the difference between an event and an incident?',
                [
                    'An event is any observable occurrence, while an incident is an event with a negative impact',
                    'An incident is any log line',
                    'They are identical',
                    'An event always causes harm',
                ],
                'An event is any observable occurrence, while an incident is an event with a negative impact',
            ],
            'vulnerability-assessment' => [
                'What is vulnerability assessment?',
                [
                    'The process of identifying and prioritising weaknesses in a system',
                    'The process of writing secure code only',
                    'A type of backup',
                    'A network cable standard',
                ],
                'The process of identifying and prioritising weaknesses in a system',
            ],
            'penetration-testing' => [
                'What is penetration testing?',
                [
                    'Authorised simulated attacks to find exploitable weaknesses',
                    'A method of encrypting data',
                    'A database migration',
                    'A user interface design technique',
                ],
                'Authorised simulated attacks to find exploitable weaknesses',
            ],
            'web-security' => [
                'Which of these is a common web security risk?',
                [
                    'Cross-site scripting (XSS)',
                    'Responsive design',
                    'Database indexing',
                    'Image compression',
                ],
                'Cross-site scripting (XSS)',
            ],
            'sql-injection' => [
                'What is SQL injection?',
                [
                    'Injecting malicious SQL through unsanitised user input',
                    'A database backup method',
                    'A type of encryption',
                    'A network protocol',
                ],
                'Injecting malicious SQL through unsanitised user input',
            ],
            'threat-modeling' => [
                'What is threat modeling?',
                [
                    'Systematically identifying potential threats and mitigations',
                    'Designing the database schema',
                    'Writing the user interface',
                    'Assigning IP addresses',
                ],
                'Systematically identifying potential threats and mitigations',
            ],

            // ---- Embedded Systems & IoT ----
            'embedded-systems' => [
                'What is an embedded system?',
                [
                    'A computer system dedicated to a specific function within a larger device',
                    'A web application',
                    'A database server',
                    'A mobile app store',
                ],
                'A computer system dedicated to a specific function within a larger device',
            ],
            'microcontrollers' => [
                'What is a microcontroller?',
                [
                    'A compact integrated circuit with a processor, memory and input/output for embedded control',
                    'A type of network switch',
                    'A database engine',
                    'A programming language',
                ],
                'A compact integrated circuit with a processor, memory and input/output for embedded control',
            ],
            'gpio' => [
                'What is GPIO?',
                [
                    'General-purpose input/output pins that can be controlled by software',
                    'A network protocol',
                    'A database query',
                    'A CSS property',
                ],
                'General-purpose input/output pins that can be controlled by software',
            ],
            'cc' => [
                'Why is C/C++ commonly used in embedded systems?',
                [
                    'It offers low-level control and predictable performance',
                    'It is the only language with loops',
                    'It cannot access hardware',
                    'It runs only in browsers',
                ],
                'It offers low-level control and predictable performance',
            ],
            'memory-management' => [
                'What is the difference between RAM and Flash memory?',
                [
                    'RAM is volatile, while Flash retains data without power',
                    'Flash is volatile, while RAM is persistent',
                    'They are identical',
                    'RAM is used only for storage',
                ],
                'RAM is volatile, while Flash retains data without power',
            ],
            'iot-fundamentals' => [
                'What is the Internet of Things (IoT)?',
                [
                    'A network of physical devices that collect and exchange data',
                    'A type of database',
                    'A frontend framework',
                    'A web browser',
                ],
                'A network of physical devices that collect and exchange data',
            ],
            'sensors-actuators' => [
                'What is the difference between a sensor and an actuator?',
                [
                    'A sensor measures a physical quantity, while an actuator performs an action',
                    'An actuator measures, while a sensor acts',
                    'They are the same device',
                    'Sensors only work offline',
                ],
                'A sensor measures a physical quantity, while an actuator performs an action',
            ],
            'mqtt' => [
                'What is MQTT?',
                [
                    'A lightweight messaging protocol for IoT devices',
                    'A database engine',
                    'A type of sensor',
                    'A CSS framework',
                ],
                'A lightweight messaging protocol for IoT devices',
            ],
            'device-communication' => [
                'How can an IoT device communicate with a server?',
                [
                    'By sending data over a network protocol such as MQTT or HTTP',
                    'Only by physical cables',
                    'Only through email',
                    'It cannot communicate',
                ],
                'By sending data over a network protocol such as MQTT or HTTP',
            ],
            'iot-security' => [
                'Why is authentication important in IoT?',
                [
                    'It ensures that only trusted devices and users can access the system',
                    'It makes devices run faster',
                    'It reduces the device size',
                    'It replaces encryption entirely',
                ],
                'It ensures that only trusted devices and users can access the system',
            ],

            // ---- Languages ----
            'php' => [
                'What is PHP mainly used for?',
                ['Building server-side web applications', 'Styling web pages', 'Editing images', 'Managing network routers'],
                'Building server-side web applications',
            ],
            'csharp' => [
                'What is C# primarily used for?',
                ['Building applications on the .NET platform and in Unity', 'Writing database queries only', 'Styling web pages', 'Configuring networks'],
                'Building applications on the .NET platform and in Unity',
            ],

            // ---- Programming fundamentals ----
            'data-structures' => [
                'What is a data structure?',
                ['A way of organising data so it can be used efficiently', 'A type of database server', 'A network cable', 'A pattern for user interfaces'],
                'A way of organising data so it can be used efficiently',
            ],
            'algorithms' => [
                'What is an algorithm?',
                ['A step-by-step procedure for solving a problem', 'A programming language', 'A type of database', 'A hardware component'],
                'A step-by-step procedure for solving a problem',
            ],
            'object-oriented-programming' => [
                'What is a class in object-oriented programming?',
                ['A blueprint for creating objects', 'A running instance of a program', 'A database table', 'A network address'],
                'A blueprint for creating objects',
            ],
            'software-design' => [
                'What is software design mainly concerned with?',
                ['Structuring a system so it is maintainable and meets its requirements', 'Choosing the colour scheme of an app', 'Installing an operating system', 'Configuring a router'],
                'Structuring a system so it is maintainable and meets its requirements',
            ],
            'version-control-git' => [
                'What is Git used for?',
                ['Tracking changes to source code over time', 'Compressing images', 'Managing database indexes', 'Configuring firewalls'],
                'Tracking changes to source code over time',
            ],
            'debugging' => [
                'What is debugging?',
                ['Finding and fixing defects in a program', 'Deleting unused files', 'Writing documentation', 'Deploying to production'],
                'Finding and fixing defects in a program',
            ],

            // ---- Web ----
            'laravel' => [
                'What is Laravel?',
                ['A PHP web application framework', 'A JavaScript runtime', 'A relational database', 'A CSS framework'],
                'A PHP web application framework',
            ],
            'wordpress' => [
                'What is WordPress?',
                ['A content management system for building websites', 'A programming language', 'A relational database', 'A network protocol'],
                'A content management system for building websites',
            ],
            'web-application-development' => [
                'What is a web application?',
                ['Software that runs in a browser and communicates with a server', 'A desktop-only program', 'A database engine', 'A network cable'],
                'Software that runs in a browser and communicates with a server',
            ],

            // ---- Mobile ----
            'ios-development' => [
                'Which language is the primary choice for modern iOS development?',
                ['Swift', 'Java', 'PHP', 'Go'],
                'Swift',
            ],
            'swift' => [
                'What is Swift?',
                ['A programming language for Apple platforms', 'A database engine', 'A web server', 'A design tool'],
                'A programming language for Apple platforms',
            ],
            'kotlin' => [
                'What is Kotlin commonly used for?',
                ['Android application development', 'Styling web pages', 'Managing servers', 'Querying data warehouses'],
                'Android application development',
            ],
            'flutter' => [
                'What is Flutter?',
                ['A framework for building cross-platform mobile apps', 'A relational database', 'A CSS preprocessor', 'A network protocol'],
                'A framework for building cross-platform mobile apps',
            ],
            'dart' => [
                'Which language does Flutter use?',
                ['Dart', 'Python', 'Ruby', 'Perl'],
                'Dart',
            ],
            'react-native' => [
                'What is React Native used for?',
                ['Building mobile apps with JavaScript and React', 'Building relational databases', 'Configuring networks', 'Designing logos'],
                'Building mobile apps with JavaScript and React',
            ],
            'cross-platform-development' => [
                'What does cross-platform development mean?',
                ['Building one app that runs on multiple platforms', 'Building an app for a single device only', 'Porting a database', 'Designing hardware'],
                'Building one app that runs on multiple platforms',
            ],

            // ---- Data ----
            'data-warehousing' => [
                'What is a data warehouse?',
                ['A central store optimised for analytics and reporting', 'A tool for editing images', 'A network device', 'A programming language'],
                'A central store optimised for analytics and reporting',
            ],
            'etl' => [
                'What does ETL stand for?',
                ['Extract, Transform, Load', 'Evaluate, Test, Launch', 'Encode, Transfer, Log', 'Edit, Trace, Link'],
                'Extract, Transform, Load',
            ],
            'apache-spark' => [
                'What is Apache Spark used for?',
                ['Large-scale distributed data processing', 'Styling web pages', 'Managing user accounts', 'Designing user interfaces'],
                'Large-scale distributed data processing',
            ],
            'big-data' => [
                'What characterises big data?',
                ['Volume, velocity and variety beyond traditional tools', 'Small, well-structured spreadsheets', 'A single database row', 'A design pattern'],
                'Volume, velocity and variety beyond traditional tools',
            ],
            'data-modeling' => [
                'What is data modeling?',
                ['Defining the structure and relationships of data', 'Rendering 3D graphics', 'Configuring a firewall', 'Writing marketing copy'],
                'Defining the structure and relationships of data',
            ],
            'data-pipelines' => [
                'What is a data pipeline?',
                ['A series of steps that move and transform data', 'A network cable', 'A user interface component', 'A database index'],
                'A series of steps that move and transform data',
            ],
            'power-bi' => [
                'What is Power BI?',
                ['A business analytics and reporting tool', 'A programming language', 'A relational database', 'A web server'],
                'A business analytics and reporting tool',
            ],
            'tableau' => [
                'What is Tableau used for?',
                ['Data visualisation and business intelligence', 'Writing backend APIs', 'Managing servers', 'Designing mobile apps'],
                'Data visualisation and business intelligence',
            ],
            'business-intelligence' => [
                'What is business intelligence?',
                ['Using data to support business decision-making', 'A hardware component', 'A network protocol', 'A design pattern'],
                'Using data to support business decision-making',
            ],
            'kpis-and-metrics' => [
                'What is a KPI?',
                ['A measurable value that shows how well an objective is met', 'A programming language', 'A database engine', 'A network device'],
                'A measurable value that shows how well an objective is met',
            ],

            // ---- AI / ML ----
            'deep-learning' => [
                'What is deep learning?',
                ['Machine learning using multi-layered neural networks', 'A method of database indexing', 'A network routing technique', 'A UI design approach'],
                'Machine learning using multi-layered neural networks',
            ],
            'tensorflow' => [
                'What is TensorFlow?',
                ['An open-source machine-learning framework', 'A relational database', 'A web server', 'A CSS library'],
                'An open-source machine-learning framework',
            ],
            'pytorch' => [
                'What is PyTorch?',
                ['A machine-learning framework popular in research', 'A network protocol', 'A database engine', 'A design tool'],
                'A machine-learning framework popular in research',
            ],
            'mlops' => [
                'What is MLOps?',
                ['Practices for deploying and operating machine-learning models reliably', 'A database backup strategy', 'A UI design system', 'A network topology'],
                'Practices for deploying and operating machine-learning models reliably',
            ],
            'model-serving' => [
                'What is model serving?',
                ['Exposing a trained model so applications can call it', 'Labelling training data', 'Cleaning a dataset', 'Designing a database'],
                'Exposing a trained model so applications can call it',
            ],
            'reinforcement-learning' => [
                'What is reinforcement learning?',
                ['Learning by interacting with an environment to maximise reward', 'Learning from fully labelled data', 'A database technique', 'A network protocol'],
                'Learning by interacting with an environment to maximise reward',
            ],
            'computer-vision' => [
                'What is computer vision?',
                ['Teaching machines to interpret images and video', 'Managing databases', 'Designing user interfaces', 'Routing network traffic'],
                'Teaching machines to interpret images and video',
            ],
            'opencv' => [
                'What is OpenCV?',
                ['A library for computer vision and image processing', 'A relational database', 'A web framework', 'A network protocol'],
                'A library for computer vision and image processing',
            ],
            'image-processing' => [
                'What is image processing?',
                ['Manipulating and analysing images with algorithms', 'Storing images in a database only', 'Designing logos', 'Configuring servers'],
                'Manipulating and analysing images with algorithms',
            ],
            'generative-ai' => [
                'What is generative AI?',
                ['AI that creates new content such as text or images', 'AI that only classifies existing data', 'A database engine', 'A network device'],
                'AI that creates new content such as text or images',
            ],
            'large-language-models' => [
                'What is a large language model?',
                ['A model trained on large text corpora to generate language', 'A relational database', 'A UI framework', 'A network protocol'],
                'A model trained on large text corpora to generate language',
            ],
            'prompt-engineering' => [
                'What is prompt engineering?',
                ['Designing inputs that guide a language model to a useful output', 'Writing SQL queries', 'Designing database schemas', 'Configuring firewalls'],
                'Designing inputs that guide a language model to a useful output',
            ],
            'retrieval-augmented-generation' => [
                'What does retrieval-augmented generation (RAG) combine?',
                ['A language model with a retrieval step over external knowledge', 'A database with a firewall', 'Two neural networks only', 'A UI with a backend'],
                'A language model with a retrieval step over external knowledge',
            ],

            // ---- IT ----
            'windows-server' => [
                'What is Windows Server?',
                ['A Microsoft operating system for server workloads', 'A mobile application', 'A JavaScript framework', 'A network cable'],
                'A Microsoft operating system for server workloads',
            ],
            'active-directory' => [
                'What is Active Directory used for?',
                ['Centralised identity and access management in Windows networks', 'Rendering web pages', 'Compressing images', 'Writing SQL queries'],
                'Centralised identity and access management in Windows networks',
            ],
            'help-desk-operations' => [
                'What is the main goal of help desk operations?',
                ['Resolving user issues efficiently and tracking them', 'Designing databases', 'Building mobile apps', 'Writing marketing copy'],
                'Resolving user issues efficiently and tracking them',
            ],
            'it-service-management' => [
                'What is IT service management (ITSM)?',
                ['Managing IT services to meet agreed levels of delivery', 'Designing user interfaces', 'Writing machine-learning models', 'Building games'],
                'Managing IT services to meet agreed levels of delivery',
            ],
            'virtualization' => [
                'What is virtualization?',
                ['Running multiple virtual machines on one physical host', 'Compressing files', 'Encrypting network traffic', 'Designing databases'],
                'Running multiple virtual machines on one physical host',
            ],

            // ---- Networking ----
            'vlans' => [
                'What is a VLAN?',
                ['A logically segmented network within a physical one', 'A type of database', 'A programming language', 'A UI component'],
                'A logically segmented network within a physical one',
            ],
            'ospf' => [
                'What is OSPF?',
                ['A routing protocol used within an autonomous system', 'A database engine', 'A UI framework', 'An image format'],
                'A routing protocol used within an autonomous system',
            ],
            'bgp' => [
                'What is BGP mainly used for?',
                ['Exchanging routing information between autonomous systems', 'Styling web pages', 'Compressing images', 'Managing databases'],
                'Exchanging routing information between autonomous systems',
            ],
            'network-design' => [
                'What does network design involve?',
                ['Planning the topology, addressing and capacity of a network', 'Writing SQL queries', 'Designing logos', 'Training ML models'],
                'Planning the topology, addressing and capacity of a network',
            ],
            'wireless-networking' => [
                'What is a key concern in wireless networking?',
                ['Signal interference and coverage', 'Database normalisation', 'Image compression', 'Code compilation'],
                'Signal interference and coverage',
            ],

            // ---- Cloud & DevOps ----
            'cloud-computing-fundamentals' => [
                'What is cloud computing?',
                ['Delivering computing resources over the internet on demand', 'Storing files only on a local disk', 'A programming language', 'A network cable'],
                'Delivering computing resources over the internet on demand',
            ],
            'aws' => [
                'What is AWS?',
                ['A cloud platform offering computing and storage services', 'A relational database', 'A JavaScript framework', 'A design tool'],
                'A cloud platform offering computing and storage services',
            ],
            'microsoft-azure' => [
                'What is Microsoft Azure?',
                ['A cloud computing platform by Microsoft', 'A programming language', 'A database engine', 'A UI library'],
                'A cloud computing platform by Microsoft',
            ],
            'docker' => [
                'What is Docker mainly used for?',
                ['Packaging applications into portable containers', 'Designing user interfaces', 'Managing DNS records', 'Training ML models'],
                'Packaging applications into portable containers',
            ],
            'kubernetes' => [
                'What is Kubernetes used for?',
                ['Orchestrating containers across a cluster', 'Designing databases', 'Editing images', 'Writing marketing copy'],
                'Orchestrating containers across a cluster',
            ],
            'linux-administration' => [
                'What does Linux administration involve?',
                ['Managing Linux servers, users and services', 'Designing user interfaces', 'Writing novels', 'Editing videos'],
                'Managing Linux servers, users and services',
            ],
            'cicd' => [
                'What is CI/CD?',
                ['Automating building, testing and deploying software', 'A database design technique', 'A UI pattern', 'A network protocol'],
                'Automating building, testing and deploying software',
            ],
            'infrastructure-as-code' => [
                'What is infrastructure as code?',
                ['Managing infrastructure through versioned configuration files', 'Writing application logic only', 'Designing logos', 'Compressing images'],
                'Managing infrastructure through versioned configuration files',
            ],
            'terraform' => [
                'What is Terraform used for?',
                ['Provisioning infrastructure declaratively', 'Styling web pages', 'Querying databases', 'Training neural networks'],
                'Provisioning infrastructure declaratively',
            ],
            'observability' => [
                'What is observability in operations?',
                ['Understanding system state from logs, metrics and traces', 'Encrypting files', 'Designing databases', 'Writing marketing copy'],
                'Understanding system state from logs, metrics and traces',
            ],

            // ---- Databases ----
            'database-design' => [
                'What is database design concerned with?',
                ['Structuring tables and relationships for correct, efficient storage', 'Designing logos', 'Configuring routers', 'Writing UI code'],
                'Structuring tables and relationships for correct, efficient storage',
            ],
            'query-optimization' => [
                'What is query optimisation?',
                ['Improving a query so it runs faster and uses fewer resources', 'Renaming database columns', 'Encrypting the database', 'Backing up the database'],
                'Improving a query so it runs faster and uses fewer resources',
            ],
            'nosql' => [
                'What is a NoSQL database?',
                ['A database that does not rely solely on relational tables', 'A database without any schema at all ever', 'A type of network', 'A UI framework'],
                'A database that does not rely solely on relational tables',
            ],
            'database-indexing' => [
                'Why is a database index used?',
                ['To speed up lookups on a column', 'To encrypt the table', 'To back up the table', 'To design the UI'],
                'To speed up lookups on a column',
            ],

            // ---- Business ----
            'it-project-management' => [
                'What does IT project management involve?',
                ['Planning and delivering IT projects on time and within scope', 'Designing databases', 'Writing machine-learning models', 'Editing images'],
                'Planning and delivering IT projects on time and within scope',
            ],
            'it-governance' => [
                'What is IT governance?',
                ['Directing and controlling IT to meet business objectives', 'A database engine', 'A network protocol', 'A UI framework'],
                'Directing and controlling IT to meet business objectives',
            ],
            'erp-systems' => [
                'What is an ERP system?',
                ['Integrated software managing core business processes', 'A programming language', 'A network device', 'An image format'],
                'Integrated software managing core business processes',
            ],
            'crm-systems' => [
                'What is a CRM system used for?',
                ['Managing interactions with customers and prospects', 'Designing databases', 'Compiling code', 'Routing packets'],
                'Managing interactions with customers and prospects',
            ],
            'digital-transformation' => [
                'What is digital transformation?',
                ['Using technology to fundamentally change how an organisation works', 'Buying new printers', 'Rewriting a single web page', 'Replacing a network cable'],
                'Using technology to fundamentally change how an organisation works',
            ],
            'process-automation' => [
                'What is process automation?',
                ['Using software to perform repetitive tasks without manual effort', 'Hiring more staff', 'Designing logos', 'Training models'],
                'Using software to perform repetitive tasks without manual effort',
            ],
            'change-management' => [
                'Why is change management important?',
                ['It helps people adopt changes successfully', 'It encrypts data', 'It compiles code', 'It designs databases'],
                'It helps people adopt changes successfully',
            ],
            'technology-consulting' => [
                'What does a technology consultant do?',
                ['Advises organisations on technology choices and strategy', 'Writes only database queries', 'Designs logos', 'Installs network cables'],
                'Advises organisations on technology choices and strategy',
            ],

            // ---- Cybersecurity ----
            'security-architecture' => [
                'What is security architecture?',
                ['Designing systems so security is built in from the start', 'Installing antivirus only', 'Writing marketing copy', 'Designing logos'],
                'Designing systems so security is built in from the start',
            ],
            'secure-coding' => [
                'What is secure coding?',
                ['Writing code that avoids common vulnerabilities', 'Writing code as fast as possible', 'Writing documentation only', 'Designing databases'],
                'Writing code that avoids common vulnerabilities',
            ],
            'identity-and-access-management' => [
                'What is identity and access management?',
                ['Managing who can access which systems and resources', 'Managing network cables', 'Designing user interfaces', 'Compressing images'],
                'Managing who can access which systems and resources',
            ],
            'ethical-hacking' => [
                'What is ethical hacking?',
                ['Authorised testing of systems to find weaknesses', 'Attacking systems without permission', 'Designing databases', 'Writing documentation'],
                'Authorised testing of systems to find weaknesses',
            ],
            'red-teaming' => [
                'What is red teaming?',
                ['Simulating a real adversary to test defences', 'A database design method', 'A UI framework', 'An image format'],
                'Simulating a real adversary to test defences',
            ],
            'exploit-development' => [
                'What is exploit development?',
                ['Researching how to turn a vulnerability into an attack', 'Designing databases', 'Writing marketing copy', 'Editing images'],
                'Researching how to turn a vulnerability into an attack',
            ],
            'digital-forensics' => [
                'What is digital forensics?',
                ['Collecting and analysing digital evidence after an incident', 'Designing databases', 'Building mobile apps', 'Routing packets'],
                'Collecting and analysing digital evidence after an incident',
            ],
            'incident-handling' => [
                'What does incident handling cover?',
                ['Detecting, containing, eradicating and recovering from incidents', 'Designing logos', 'Writing SQL', 'Training models'],
                'Detecting, containing, eradicating and recovering from incidents',
            ],
            'malware-analysis' => [
                'What is malware analysis?',
                ['Studying malicious software to understand its behaviour', 'Designing databases', 'Writing marketing copy', 'Building UIs'],
                'Studying malicious software to understand its behaviour',
            ],
            'evidence-collection' => [
                'Why is evidence collection done carefully in forensics?',
                ['To preserve integrity so it remains admissible', 'To make files smaller', 'To speed up the network', 'To design a database'],
                'To preserve integrity so it remains admissible',
            ],
            'cryptography' => [
                'What is cryptography used for?',
                ['Protecting information using mathematical techniques', 'Designing databases', 'Building UIs', 'Routing packets'],
                'Protecting information using mathematical techniques',
            ],

            // ---- UI/UX ----
            'ui-design' => [
                'What does UI design focus on?',
                ['The visual and interactive elements of an interface', 'Database schema', 'Network topology', 'Server provisioning'],
                'The visual and interactive elements of an interface',
            ],
            'ux-research' => [
                'What is UX research?',
                ['Studying users to inform product design decisions', 'Designing databases', 'Writing backend code', 'Configuring servers'],
                'Studying users to inform product design decisions',
            ],
            'wireframing' => [
                'What is a wireframe?',
                ['A low-fidelity layout of a screen', 'A finished visual design', 'A database table', 'A network diagram'],
                'A low-fidelity layout of a screen',
            ],
            'prototyping' => [
                'What is prototyping in design?',
                ['Creating an early interactive version to test ideas', 'Writing production code', 'Designing databases', 'Routing packets'],
                'Creating an early interactive version to test ideas',
            ],
            'figma' => [
                'What is Figma?',
                ['A collaborative interface design tool', 'A relational database', 'A JavaScript framework', 'A network protocol'],
                'A collaborative interface design tool',
            ],
            'design-systems' => [
                'What is a design system?',
                ['A shared library of components and guidelines', 'A database engine', 'A network topology', 'A programming language'],
                'A shared library of components and guidelines',
            ],
            'interaction-design' => [
                'What is interaction design concerned with?',
                ['How users interact with a product over time', 'Database normalisation', 'Network addressing', 'Server hardening'],
                'How users interact with a product over time',
            ],
            'accessibility' => [
                'Why is accessibility important in design?',
                ['It lets people with disabilities use the product', 'It makes the app faster', 'It reduces database size', 'It encrypts data'],
                'It lets people with disabilities use the product',
            ],

            // ---- Game Development ----
            'unity' => [
                'What is Unity?',
                ['A game engine for building 2D and 3D games', 'A relational database', 'A web framework', 'A network protocol'],
                'A game engine for building 2D and 3D games',
            ],
            'unreal-engine' => [
                'What is Unreal Engine?',
                ['A game engine known for high-fidelity graphics', 'A database engine', 'A CSS framework', 'A network protocol'],
                'A game engine known for high-fidelity graphics',
            ],
            'game-design' => [
                'What does game design cover?',
                ['Rules, mechanics and player experience', 'Database schema', 'Network routing', 'Server provisioning'],
                'Rules, mechanics and player experience',
            ],
            'gameplay-programming' => [
                'What is gameplay programming?',
                ['Implementing game mechanics and player interactions', 'Designing databases', 'Writing marketing copy', 'Configuring firewalls'],
                'Implementing game mechanics and player interactions',
            ],
            '3d-mathematics' => [
                'Why is 3D mathematics important in games?',
                ['It underpins transforms, physics and rendering', 'It speeds up the database', 'It encrypts traffic', 'It designs the UI'],
                'It underpins transforms, physics and rendering',
            ],

            // ---- Quality Assurance ----
            'manual-testing' => [
                'What is manual testing?',
                ['A person executing test cases without automation', 'Automated regression only', 'Writing production code', 'Designing databases'],
                'A person executing test cases without automation',
            ],
            'test-case-design' => [
                'What is a test case?',
                ['A set of steps and expected results for verifying behaviour', 'A database row', 'A network packet', 'A UI component'],
                'A set of steps and expected results for verifying behaviour',
            ],
            'bug-reporting' => [
                'What makes a good bug report?',
                ['Clear steps to reproduce and the expected versus actual result', 'A vague description', 'Only a screenshot', 'Only the severity'],
                'Clear steps to reproduce and the expected versus actual result',
            ],
            'test-automation' => [
                'What is test automation?',
                ['Using tools to run tests without manual effort', 'Writing production features', 'Designing databases', 'Editing images'],
                'Using tools to run tests without manual effort',
            ],
            'selenium' => [
                'What is Selenium used for?',
                ['Automating web browser tests', 'Designing databases', 'Managing servers', 'Training models'],
                'Automating web browser tests',
            ],
            'performance-testing' => [
                'What is performance testing?',
                ['Checking how a system behaves under load', 'Testing the colour scheme', 'Testing the database schema', 'Testing documentation'],
                'Checking how a system behaves under load',
            ],
            'api-testing' => [
                'What is API testing?',
                ['Verifying that an API returns correct responses', 'Testing the UI colours', 'Testing the network cable', 'Testing the printer'],
                'Verifying that an API returns correct responses',
            ],

            // ---- Blockchain ----
            'blockchain-fundamentals' => [
                'What is a blockchain?',
                ['A distributed, append-only ledger', 'A relational database', 'A network protocol', 'A UI framework'],
                'A distributed, append-only ledger',
            ],
            'solidity' => [
                'What is Solidity used for?',
                ['Writing smart contracts on Ethereum', 'Styling web pages', 'Querying relational databases', 'Designing logos'],
                'Writing smart contracts on Ethereum',
            ],
            'smart-contracts' => [
                'What is a smart contract?',
                ['Self-executing code stored on a blockchain', 'A legal document only', 'A database index', 'A UI component'],
                'Self-executing code stored on a blockchain',
            ],
            'ethereum' => [
                'What is Ethereum?',
                ['A blockchain platform supporting smart contracts', 'A relational database', 'A JavaScript framework', 'A design tool'],
                'A blockchain platform supporting smart contracts',
            ],
            'web3-development' => [
                'What is Web3 development?',
                ['Building decentralised applications on blockchains', 'Building static HTML pages', 'Designing databases', 'Routing packets'],
                'Building decentralised applications on blockchains',
            ],

            // ---- Embedded & Robotics ----
            'embedded-c' => [
                'What is embedded C used for?',
                ['Programming microcontrollers and embedded devices', 'Building web frontends', 'Designing databases', 'Editing images'],
                'Programming microcontrollers and embedded devices',
            ],
            'rtos' => [
                'What is an RTOS?',
                ['An operating system designed for real-time constraints', 'A relational database', 'A network protocol', 'A UI framework'],
                'An operating system designed for real-time constraints',
            ],
            'firmware-development' => [
                'What is firmware?',
                ['Low-level software embedded in a hardware device', 'A web application', 'A database engine', 'A design tool'],
                'Low-level software embedded in a hardware device',
            ],
            'hardware-software-integration' => [
                'What does hardware-software integration involve?',
                ['Making software work correctly with the underlying hardware', 'Designing logos', 'Writing marketing copy', 'Managing DNS'],
                'Making software work correctly with the underlying hardware',
            ],
            'signal-processing' => [
                'What is signal processing?',
                ['Analysing and transforming signals such as audio or sensor data', 'Designing databases', 'Writing UI code', 'Routing packets'],
                'Analysing and transforming signals such as audio or sensor data',
            ],
            'pcb-design' => [
                'What is PCB design?',
                ['Laying out the physical circuit board for electronics', 'Designing a database', 'Writing a web page', 'Training a model'],
                'Laying out the physical circuit board for electronics',
            ],
            'robotics' => [
                'What is robotics?',
                ['Designing and programming machines that sense and act', 'A database technique', 'A UI pattern', 'A network protocol'],
                'Designing and programming machines that sense and act',
            ],
            'ros' => [
                'What is ROS?',
                ['A framework for writing robot software', 'A relational database', 'A CSS library', 'A network cable'],
                'A framework for writing robot software',
            ],
            'control-systems' => [
                'What is a control system?',
                ['A system that regulates behaviour using feedback', 'A database engine', 'A UI framework', 'A network protocol'],
                'A system that regulates behaviour using feedback',
            ],

            // ---- Remaining taxonomy skills ----
            'c' => [
                'What is C++ commonly used for?',
                ['High-performance systems and embedded software', 'Styling web pages', 'Managing databases', 'Designing logos'],
                'High-performance systems and embedded software',
            ],
            'css-tailwind' => [
                'What is Tailwind CSS?',
                ['A utility-first CSS framework', 'A JavaScript runtime', 'A relational database', 'A network protocol'],
                'A utility-first CSS framework',
            ],
            'graphql' => [
                'What is GraphQL?',
                ['A query language for APIs', 'A relational database', 'A CSS framework', 'A build tool'],
                'A query language for APIs',
            ],
            'java' => [
                'What is Java commonly used for?',
                ['Building cross-platform enterprise applications', 'Styling web pages', 'Editing images', 'Routing packets'],
                'Building cross-platform enterprise applications',
            ],
            'nextjs' => [
                'What is Next.js?',
                ['A React framework for production web applications', 'A relational database', 'A CSS preprocessor', 'A network protocol'],
                'A React framework for production web applications',
            ],
            'nodejs' => [
                'What is Node.js?',
                ['A JavaScript runtime for building server-side applications', 'A relational database', 'A CSS framework', 'An image format'],
                'A JavaScript runtime for building server-side applications',
            ],
            'numpy' => [
                'What is NumPy used for?',
                ['Numerical computing with arrays in Python', 'Designing user interfaces', 'Managing networks', 'Writing SQL'],
                'Numerical computing with arrays in Python',
            ],
            'pandas' => [
                'What is Pandas used for?',
                ['Data analysis and manipulation in Python', 'Styling web pages', 'Configuring routers', 'Building games'],
                'Data analysis and manipulation in Python',
            ],
            'react' => [
                'What is React?',
                ['A JavaScript library for building user interfaces', 'A relational database', 'A network protocol', 'A CSS framework'],
                'A JavaScript library for building user interfaces',
            ],
            'rust' => [
                'What is Rust known for?',
                ['Memory safety without a garbage collector', 'Designing databases', 'Styling web pages', 'Managing DNS'],
                'Memory safety without a garbage collector',
            ],
            'typescript' => [
                'What is TypeScript?',
                ['JavaScript with static types', 'A relational database', 'A CSS framework', 'A network protocol'],
                'JavaScript with static types',
            ],
        ];

        foreach ($items as $skillSlug => [$question, $options, $correctAnswer]) {
            $skill = Skill::where('slug', $skillSlug)->first();

            if (! $skill) {
                continue; // Skip silently if SkillSeeder hasn't created it yet.
            }

            BaselineAssessmentItem::updateOrCreate(
                ['assessment_version' => 'v1.0', 'item_id' => $skillSlug.'-001'],
                [
                    'item_type' => 'single_choice',
                    'question_text' => $question,
                    'skill_id' => $skill->id,
                    'options' => $options,
                    'correct_answer' => $correctAnswer,
                    'scoring_rule' => null,
                    'weight' => 1.000,
                    'is_active' => true,
                ]
            );
        }
    }
}
